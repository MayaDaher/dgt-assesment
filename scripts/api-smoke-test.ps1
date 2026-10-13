<#
.SYNOPSIS
    End-to-end smoke test of the API using curl against a running server and the seeded database.

.DESCRIPTION
    Requires: `php artisan migrate --seed` and a running server (`php artisan serve`).
    The script temporarily changes a few seeded products (price, stock, status) to exercise
    cart edge cases and always restores them at the end.

    The auth rate limiter allows 20 auth requests per minute per IP, so wait a minute
    between consecutive runs.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts/api-smoke-test.ps1
    powershell -ExecutionPolicy Bypass -File scripts/api-smoke-test.ps1 -BaseUrl http://127.0.0.1:8000/api
#>
param(
    [string]$BaseUrl = 'http://127.0.0.1:8000/api'
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
$TempDir = Join-Path ([IO.Path]::GetTempPath()) "api-smoke-$PID"
New-Item -ItemType Directory -Force -Path $TempDir | Out-Null

$script:Passed = 0
$script:Failed = 0
$script:Failures = @()

function Section([string]$title) {
    Write-Host ''
    Write-Host "=== $title ===" -ForegroundColor Cyan
}

function Check([string]$name, [bool]$condition, [string]$info = '') {
    if ($condition) {
        $script:Passed++
        Write-Host "  PASS  $name" -ForegroundColor Green
    } else {
        $script:Failed++
        $script:Failures += "$name $info"
        Write-Host "  FAIL  $name $info" -ForegroundColor Red
    }
}

<#
    Sends a request and returns @{ Status; Json; Headers; Raw }.
    -NoAccept omits the Accept header to prove the API still answers with JSON.
#>
function Api {
    param(
        [string]$Method,
        [string]$Path,
        $Body = $null,
        [string]$Token = $null,
        [switch]$NoAccept,
        [string]$Url = $null,
        [hashtable]$ExtraHeaders = @{}
    )

    $target = if ($Url) { $Url } else { "$BaseUrl$Path" }
    $headerFile = Join-Path $TempDir 'headers.txt'
    $curlArgs = @('-s', '-g', '-X', $Method, '-D', $headerFile, '-w', "`n%{http_code}")

    if (-not $NoAccept) { $curlArgs += @('-H', 'Accept: application/json') }
    if ($Token) { $curlArgs += @('-H', "Authorization: Bearer $Token") }
    foreach ($header in $ExtraHeaders.GetEnumerator()) {
        $curlArgs += @('-H', "$($header.Key): $($header.Value)")
    }
    if ($null -ne $Body) {
        $bodyFile = Join-Path $TempDir 'body.json'
        [IO.File]::WriteAllText($bodyFile, ($Body | ConvertTo-Json -Compress -Depth 5))
        $curlArgs += @('-H', 'Content-Type: application/json', '--data-binary', "@$bodyFile")
    }

    $output = & curl.exe @curlArgs $target
    $lines = @($output -split "`n")
    $status = [int]$lines[-1]
    $raw = ($lines[0..([Math]::Max(0, $lines.Length - 2))] -join "`n").Trim()
    if ($lines.Length -eq 1) { $raw = '' }

    $json = $null
    if ($raw.StartsWith('{') -or $raw.StartsWith('[')) {
        try { $json = $raw | ConvertFrom-Json } catch { $json = $null }
    }

    $headers = @{}
    foreach ($line in (Get-Content $headerFile)) {
        if ($line -match '^([^:]+):\s*(.*)$') { $headers[$matches[1].ToLower()] = $matches[2].Trim() }
    }

    return @{ Status = $status; Json = $json; Headers = $headers; Raw = $raw }
}

function Tinker([string]$code) {
    Push-Location $ProjectRoot
    try { return (& php artisan tinker --execute=$code) -join "`n" } finally { Pop-Location }
}

function Artisan([string]$command) {
    Push-Location $ProjectRoot
    try { return (& php artisan @($command -split '\s+')) -join "`n" } finally { Pop-Location }
}

function Has($object, [string]$property) {
    return $null -ne $object -and $object.PSObject.Properties.Name -contains $property
}

function CartLine($response, $productId) {
    return @($response.Json.data.items | Where-Object { $_.product.id -eq $productId })[0]
}

function DrainQueuedListeners {
    Push-Location $ProjectRoot
    $prev = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        # Two passes: a just-committed jobs row can miss the first --stop-when-empty poll.
        # Continue (not Stop) so php stderr from queue:work cannot kill the worker.
        1..2 | ForEach-Object {
            & php artisan queue:work --stop-when-empty --max-jobs=50 --tries=3 --timeout=15 --sleep=0 2>&1 | Out-Null
        }
    } finally {
        $ErrorActionPreference = $prev
        Pop-Location
    }
}

function LaravelLogMatchCount([string]$pattern) {
    $log = Join-Path $ProjectRoot 'storage\logs\laravel.log'
    if (-not (Test-Path $log)) { return 0 }
    return @(Select-String -Path $log -Pattern $pattern).Count
}

function StatusChangedLogPattern([int]$orderId, [string]$from, [string]$to, [string]$source) {
    $fromJson = if ([string]::IsNullOrEmpty($from) -or $from -eq 'null') { 'null' } else { "`"$from`"" }
    return ('"order_id":{0}.*"from_status":{1}.*"to_status":"{2}".*"source":"{3}"' -f $orderId, $fromJson, $to, $source)
}

function AssertStatusChangedLog([string]$name, [int]$orderId, [string]$from, [string]$to, [string]$source) {
    DrainQueuedListeners
    $pattern = StatusChangedLogPattern $orderId $from $to $source
    $count = LaravelLogMatchCount $pattern
    Check $name ($count -ge 1) "count=$count pattern=$pattern"
    return $count
}

function AssertNoNewStatusChangedLog([string]$name, [int]$orderId, [string]$from, [string]$to, [string]$source, [int]$before) {
    DrainQueuedListeners
    $pattern = StatusChangedLogPattern $orderId $from $to $source
    $count = LaravelLogMatchCount $pattern
    Check $name ($count -eq $before) "before=$before after=$count"
}

function CheckoutBody($extra = $null) {
    $body = @{
        expected_total = '0.00'
        shipping = @{
            name = 'Maya Daher'
            phone = '+961 70 123 456'
            address_line1 = 'Hamra St., Building 12, Floor 3'
            address_line2 = 'Apartment 5'
            city = 'Beirut'
            state = 'Beirut Governorate'
            postal_code = '1103'
            country = 'Lebanon'
        }
    }
    if ($null -ne $extra) {
        foreach ($key in @($extra.Keys)) { $body[$key] = $extra[$key] }
    }
    return $body
}

function CartTotal([string]$token) {
    $cart = Api GET '/cart' -Token $token
    $total = $cart.Json.data.total
    if ([string]::IsNullOrWhiteSpace([string]$total)) { return '0.00' }
    return [string]$total
}

function CheckoutQuoted([string]$token, $extra = $null) {
    $merged = @{ expected_total = (CartTotal $token) }
    if ($null -ne $extra) {
        foreach ($key in @($extra.Keys)) { $merged[$key] = $extra[$key] }
    }
    return CheckoutBody $merged
}

function RestoreSeededCatalog {
    Tinker "App\Models\Product::withTrashed()->whereIn('sku', ['AUD-APL-APP2','SNK-LTD-001','FSH-MEN-DNJ','HOM-APP-ESP','AUD-SNY-XM5','BOK-CLN-ARC'])->restore(); App\Models\Product::query()->where('sku', 'AUD-APL-APP2')->update(['price' => '249.00']); App\Models\Product::query()->where('sku', 'SNK-LTD-001')->update(['stock_quantity' => 5, 'price' => '120.00', 'name' => 'Limited Edition Sneakers']); App\Models\Product::query()->where('sku', 'FSH-MEN-DNJ')->update(['stock_quantity' => 35]); App\Models\Product::query()->where('sku', 'HOM-APP-ESP')->update(['status' => 'active']);"
}

function IsSortedBy($items, [scriptblock]$key, [switch]$Descending) {
    $values = @($items | ForEach-Object $key)
    for ($i = 1; $i -lt $values.Count; $i++) {
        if ($Descending) { if ($values[$i - 1] -lt $values[$i]) { return $false } }
        else { if ($values[$i - 1] -gt $values[$i]) { return $false } }
    }
    return $true
}

# ---------------------------------------------------------------------------
# Fixtures: resolve seeded product ids by SKU (ids differ between databases).
# ---------------------------------------------------------------------------
$probe = Api GET '/products'
if ($probe.Status -ne 200) {
    Write-Host "Server not reachable at $BaseUrl (status $($probe.Status)). Start it with: php artisan serve" -ForegroundColor Red
    exit 1
}

$skuJson = Tinker "echo App\Models\Product::withTrashed()->whereIn('sku', ['SNK-LTD-001','AUD-APL-APP2','PHN-GGL-PX8','SPT-OUT-HKP','HOM-APP-ESP','BOK-CLN-ARC','AUD-SNY-XM5','FSH-MEN-DNJ','BOK-DDI-APP'])->pluck('id','sku')->toJson();"
$ids = ($skuJson | Select-String -Pattern '\{.*\}').Matches[0].Value | ConvertFrom-Json
$Sneakers = [int]$ids.'SNK-LTD-001'      # stock 5, 120.00
$AirPods = [int]$ids.'AUD-APL-APP2'      # stock 60, 249.00
$Pixel = [int]$ids.'PHN-GGL-PX8'         # out of stock
$Poles = [int]$ids.'SPT-OUT-HKP'         # inactive
$Espresso = [int]$ids.'HOM-APP-ESP'      # stock 10, 549.00
$Book = [int]$ids.'BOK-CLN-ARC'          # soft-deleted during the cart tests
$Sony = [int]$ids.'AUD-SNY-XM5'          # stock 40, 399.99
$Jacket = [int]$ids.'FSH-MEN-DNJ'        # stock 35, 79.50
$DataBook = [int]$ids.'BOK-DDI-APP'      # stock 80, 45.50
$null = RestoreSeededCatalog

$RunId = Get-Date -Format 'yyyyMMddHHmmss'
$Password = 'Secret123'

try {
    # =======================================================================
    Section 'General API behaviour'
    # =======================================================================
    $r = Api GET '/products'
    Check 'GET /products returns 200 with success envelope' ($r.Status -eq 200 -and $r.Json.success -eq $true -and $null -ne $r.Json.data)

    $r = Api GET '' -Url ($BaseUrl -replace '/api$', '/up')
    Check 'GET /up health check returns 200' ($r.Status -eq 200)

    $r = Api GET '/does-not-exist'
    Check 'Unknown endpoint returns JSON 404 NOT_FOUND' ($r.Status -eq 404 -and $r.Json.success -eq $false -and $r.Json.code -eq 'NOT_FOUND')

    $r = Api POST '/products' @{}
    Check 'Wrong HTTP method returns 405 METHOD_NOT_ALLOWED' ($r.Status -eq 405 -and $r.Json.code -eq 'METHOD_NOT_ALLOWED')

    $r = Api GET '/orders' -NoAccept
    Check 'Protected endpoint without Accept header returns JSON 401 (no redirect)' ($r.Status -eq 401 -and $r.Json.code -eq 'UNAUTHENTICATED')

    $r = Api GET '/products' -NoAccept
    Check 'Public endpoint without Accept header still returns JSON' ($r.Status -eq 200 -and $r.Headers['content-type'] -like 'application/json*')

    # =======================================================================
    Section 'Auth: register'
    # =======================================================================
    $email = "smoke+$RunId@example.com"
    $r = Api POST '/auth/register' @{ name = 'Smoke Tester'; email = "  Smoke+$RunId@Example.com "; password = $Password; password_confirmation = $Password; device_name = 'curl' }
    Check 'Register returns 201' ($r.Status -eq 201) "status=$($r.Status) $($r.Raw)"
    Check 'Register normalizes email (trim + lowercase)' ($r.Json.data.user.email -eq $email)
    Check 'Register always creates a customer' ($r.Json.data.user.role -eq 'customer')
    Check 'Register returns Bearer token with expiry' ($r.Json.data.access_token -and $r.Json.data.token_type -eq 'Bearer' -and $r.Json.data.expires_at)
    Check 'Register never exposes the password' (-not (Has $r.Json.data.user 'password'))
    $SmokeToken = $r.Json.data.access_token

    $r = Api POST '/auth/register' @{ name = 'Mallory'; email = "smoke+admin$RunId@example.com"; password = $Password; password_confirmation = $Password; role = 'admin' }
    Check 'Register ignores role=admin (no privilege escalation)' ($r.Status -eq 201 -and $r.Json.data.user.role -eq 'customer')

    $r = Api POST '/auth/register' @{ name = 'Dup'; email = $email.ToUpper(); password = $Password; password_confirmation = $Password }
    Check 'Register rejects duplicate email regardless of case (422)' ($r.Status -eq 422 -and (Has $r.Json.errors 'email'))

    $r = Api POST '/auth/register' @{ name = 'Weak'; email = "weak$RunId@example.com"; password = 'password'; password_confirmation = 'password' }
    Check 'Register rejects weak password (422)' ($r.Status -eq 422 -and (Has $r.Json.errors 'password'))

    $r = Api POST '/auth/register' @{ name = 'Mismatch'; email = "mismatch$RunId@example.com"; password = $Password; password_confirmation = 'Other1234' }
    Check 'Register rejects password confirmation mismatch (422)' ($r.Status -eq 422 -and (Has $r.Json.errors 'password'))

    $r = Api POST '/auth/register' @{}
    Check 'Register requires name, email, password (422 VALIDATION_FAILED)' ($r.Status -eq 422 -and $r.Json.code -eq 'VALIDATION_FAILED' -and (Has $r.Json.errors 'name') -and (Has $r.Json.errors 'email') -and (Has $r.Json.errors 'password'))

    # =======================================================================
    Section 'Auth: login, me, logout'
    # =======================================================================
    $r = Api POST '/auth/login' @{ email = 'CUSTOMER@example.com'; password = 'Password123'; device_name = 'phone' }
    Check 'Login works with case-insensitive email' ($r.Status -eq 200 -and $r.Json.data.user.email -eq 'customer@example.com' -and $r.Json.data.access_token)
    $CustomerToken = $r.Json.data.access_token

    $r = Api POST '/auth/login' @{ email = 'admin@example.com'; password = 'Password123' }
    Check 'Admin can log in and has role admin' ($r.Status -eq 200 -and $r.Json.data.user.role -eq 'admin')
    $AdminToken = $r.Json.data.access_token

    $r = Api POST '/auth/login' @{ email = 'customer@example.com'; password = 'WrongPass1' }
    Check 'Login with wrong password returns 401 INVALID_CREDENTIALS' ($r.Status -eq 401 -and $r.Json.code -eq 'INVALID_CREDENTIALS')

    $r = Api POST '/auth/login' @{ email = "nobody$RunId@example.com"; password = 'WrongPass1' }
    Check 'Login with unknown email returns the same 401 (no user enumeration)' ($r.Status -eq 401 -and $r.Json.code -eq 'INVALID_CREDENTIALS')

    $r = Api POST '/auth/login' @{ email = 'not-an-email' }
    Check 'Login validation returns 422' ($r.Status -eq 422 -and (Has $r.Json.errors 'email') -and (Has $r.Json.errors 'password'))

    $r = Api GET '/auth/me' -Token $CustomerToken
    Check 'GET /auth/me returns the current user' ($r.Status -eq 200 -and $r.Json.data.email -eq 'customer@example.com' -and $r.Json.success -eq $true)

    $r = Api GET '/auth/me'
    Check 'GET /auth/me without token returns 401' ($r.Status -eq 401 -and $r.Json.code -eq 'UNAUTHENTICATED')

    $r = Api GET '/auth/me' -Token 'garbage-token'
    Check 'GET /auth/me with invalid token returns 401' ($r.Status -eq 401)

    $r = Api POST '/auth/login' @{ email = $email; password = $Password; device_name = 'laptop' }
    $SmokeToken2 = $r.Json.data.access_token
    $r = Api POST '/auth/logout' -Token $SmokeToken
    Check 'Logout returns 200' ($r.Status -eq 200)
    Check 'Logged-out token is rejected' ((Api GET '/auth/me' -Token $SmokeToken).Status -eq 401)
    Check 'Other device token still works after logout' ((Api GET '/auth/me' -Token $SmokeToken2).Status -eq 200)

    $r = Api POST '/auth/login' @{ email = $email; password = $Password }
    $SmokeToken3 = $r.Json.data.access_token
    $r = Api POST '/auth/logout-all' -Token $SmokeToken2
    Check 'Logout-all returns 200' ($r.Status -eq 200)
    Check 'Logout-all revokes every token' ((Api GET '/auth/me' -Token $SmokeToken2).Status -eq 401 -and (Api GET '/auth/me' -Token $SmokeToken3).Status -eq 401)

    # =======================================================================
    Section 'Products: listing'
    # =======================================================================
    $r = Api GET '/products'
    Check 'List returns 200 with data/links/meta' ($r.Status -eq 200 -and (Has $r.Json 'links') -and (Has $r.Json 'meta') -and $r.Json.success -eq $true)
    Check 'Default page size is 15' ($r.Json.data.Count -eq 15 -and $r.Json.meta.per_page -eq 15)
    Check 'Default sort is newest first' (IsSortedBy $r.Json.data { [datetime]$_.created_at } -Descending)
    $first = $r.Json.data[0]
    Check 'Product item has all documented fields' ((Has $first 'id') -and (Has $first 'name') -and (Has $first 'sku') -and (Has $first 'description') -and (Has $first 'price') -and (Has $first 'stock_quantity') -and (Has $first 'available_quantity') -and (Has $first 'status') -and (Has $first 'in_stock') -and (Has $first 'category') -and (Has $first 'created_at'))
    Check 'Price is a 2-decimal string' ($first.price -is [string] -and $first.price -match '^\d+\.\d{2}$')
    Check 'available_quantity matches on-hand when nothing is reserved' ($first.available_quantity -eq $first.stock_quantity)

    $r = Api GET '/products?per_page=100'
    $total = $r.Json.meta.total
    Check 'Only active products are listed' (@($r.Json.data | Where-Object { $_.status -ne 'active' }).Count -eq 0)
    Check 'Inactive seeded product (hiking poles) is hidden' (@($r.Json.data | Where-Object { $_.sku -eq 'SPT-OUT-HKP' }).Count -eq 0)

    $r = Api GET '/products?per_page=5&page=2&sort=name'
    Check 'Pagination meta is correct' ($r.Json.data.Count -eq 5 -and $r.Json.meta.current_page -eq 2 -and $r.Json.meta.per_page -eq 5 -and $r.Json.meta.last_page -eq [Math]::Ceiling($total / 5))
    Check 'Pagination links keep the query string' ($r.Json.links.next -like '*sort=name*' -and $r.Json.links.next -like '*per_page=5*')

    $r = Api GET '/products?page=9999'
    Check 'Page beyond the last returns 200 with empty data' ($r.Status -eq 200 -and $r.Json.data.Count -eq 0)

    $r = Api GET '/products?search=HEADPHONES'
    $searchHits = @($r.Json.data)
    Check 'Search is case-insensitive and matches by name' ($r.Status -eq 200 -and $searchHits.Count -ge 1 -and @($searchHits | Where-Object { $_.sku -eq 'AUD-SNY-XM5' }).Count -eq 1 -and @($searchHits | Where-Object { ($_.name + ' ' + $_.sku + ' ' + $_.description) -notmatch 'headphones' }).Count -eq 0)

    $r = Api GET '/products?search=snk-ltd'
    Check 'Search matches SKU (case-insensitive, partial)' ($r.Status -eq 200 -and @($r.Json.data | Where-Object { $_.sku -eq 'SNK-LTD-001' }).Count -eq 1)

    $r = Api GET '/products?search=concurrency%20demo'
    Check 'Search matches description' ($r.Status -eq 200 -and @($r.Json.data | Where-Object { $_.sku -eq 'SNK-LTD-001' }).Count -eq 1)

    $r = Api GET '/products?search=50%25'
    Check 'Search treats % literally (no wildcard injection)' ($r.Status -eq 200 -and $r.Json.meta.total -eq 0)

    $r = Api GET '/products?min_price=100&max_price=400&per_page=100'
    Check 'Price range filter is inclusive and correct' ($r.Json.data.Count -gt 0 -and @($r.Json.data | Where-Object { [decimal]$_.price -lt 100 -or [decimal]$_.price -gt 400 }).Count -eq 0)

    $r = Api GET '/products?min_price=120&max_price=120'
    Check 'Exact price boundary (120.00) is included' (@($r.Json.data | Where-Object { $_.sku -eq 'SNK-LTD-001' }).Count -eq 1)

    $r = Api GET '/products?availability=in_stock&per_page=100'
    Check 'availability=in_stock returns only products with on-hand stock' ($r.Json.data.Count -gt 0 -and @($r.Json.data | Where-Object { $_.stock_quantity -le 0 }).Count -eq 0)

    $r = Api GET '/products?availability=out_of_stock&per_page=100'
    Check 'availability=out_of_stock returns only products with zero stock' ($r.Json.data.Count -gt 0 -and @($r.Json.data | Where-Object { $_.stock_quantity -ne 0 }).Count -eq 0)

    $r = Api GET '/products?category=electronics&per_page=100'
    $slugs = @($r.Json.data | ForEach-Object { $_.category.slug } | Sort-Object -Unique)
    Check 'Category filter includes subcategories' ($r.Json.data.Count -gt 0 -and @($slugs | Where-Object { $_ -notlike 'electronics-*' }).Count -eq 0 -and $slugs.Count -ge 2)

    $r = Api GET '/products?category=electronics-phones&per_page=100'
    Check 'Subcategory filter only returns that subcategory' ($r.Json.data.Count -gt 0 -and @($r.Json.data | Where-Object { $_.category.slug -ne 'electronics-phones' }).Count -eq 0)

    foreach ($case in @(
            @{ Sort = 'price'; Key = { [decimal]$_.price }; Desc = $false },
            @{ Sort = '-price'; Key = { [decimal]$_.price }; Desc = $true },
            @{ Sort = 'name'; Key = { $_.name.ToLower() }; Desc = $false },
            @{ Sort = '-name'; Key = { $_.name.ToLower() }; Desc = $true },
            @{ Sort = 'created_at'; Key = { [datetime]$_.created_at }; Desc = $false },
            @{ Sort = '-created_at'; Key = { [datetime]$_.created_at }; Desc = $true }
        )) {
        $r = Api GET "/products?sort=$($case.Sort)&per_page=100"
        Check "sort=$($case.Sort) orders correctly" ($r.Status -eq 200 -and (IsSortedBy $r.Json.data $case.Key -Descending:$case.Desc))
    }

    $r = Api GET '/products?search=pro&category=electronics&availability=in_stock&max_price=1000&sort=-price'
    Check 'Combined filters are all applied' ($r.Status -eq 200 -and $r.Json.data.Count -gt 0 -and @($r.Json.data | Where-Object { (($_.name + ' ' + $_.sku + ' ' + $_.description) -notmatch 'pro') -or $_.category.slug -notlike 'electronics-*' -or $_.stock_quantity -le 0 -or [decimal]$_.price -gt 1000 }).Count -eq 0 -and (IsSortedBy $r.Json.data { [decimal]$_.price } -Descending))

    $r = Api GET ('/products?sort=hacked&availability=maybe&per_page=500&min_price=-1&category=nope&page=0&search=' + ('x' * 101))
    $expected = @('sort', 'availability', 'per_page', 'min_price', 'category', 'page', 'search')
    $missing = @($expected | Where-Object { -not (Has $r.Json.errors $_) })
    Check 'Invalid query params return 422 with an error per field' ($r.Status -eq 422 -and $r.Json.code -eq 'VALIDATION_FAILED' -and $missing.Count -eq 0) "missing errors: $($missing -join ',')"

    $r = Api GET '/products?min_price=100&max_price=10'
    Check 'max_price lower than min_price returns 422' ($r.Status -eq 422 -and (Has $r.Json.errors 'max_price'))

    # =======================================================================
    Section 'Products: details'
    # =======================================================================
    $r = Api GET "/products/$Sneakers"
    Check 'GET /products/{id} returns the product with category' ($r.Status -eq 200 -and $r.Json.data.sku -eq 'SNK-LTD-001' -and $r.Json.data.price -eq '120.00' -and $r.Json.data.category.slug -eq 'fashion-shoes')

    $r = Api GET "/products/$Poles"
    Check 'Inactive product returns 404 Product not found' ($r.Status -eq 404 -and $r.Json.message -eq 'Product not found.' -and $r.Json.code -eq 'RESOURCE_NOT_FOUND')

    $r = Api GET '/products/999999'
    Check 'Missing product returns 404 Product not found' ($r.Status -eq 404 -and $r.Json.message -eq 'Product not found.')

    $r = Api GET '/products/abc'
    Check 'Non-numeric product id returns 404' ($r.Status -eq 404)

    # =======================================================================
    Section 'Categories'
    # =======================================================================
    $r = Api GET '/categories'
    Check 'GET /categories returns the active tree' ($r.Status -eq 200 -and $r.Json.data.Count -eq 5 -and @($r.Json.data | Where-Object { $_.slug -eq 'electronics' })[0].children.Count -eq 3)

    $r = Api GET '/categories/electronics'
    Check 'GET /categories/{slug} returns category with children' ($r.Status -eq 200 -and $r.Json.data.slug -eq 'electronics' -and $r.Json.data.children.Count -eq 3)

    $r = Api GET '/categories/unknown-category'
    Check 'Unknown category returns 404 Category not found' ($r.Status -eq 404 -and $r.Json.message -eq 'Category not found.')

    # =======================================================================
    Section 'Cart: guest cart'
    # =======================================================================
    $r = Api GET '/cart'
    $GuestToken = $r.Headers['x-cart-token']
    Check 'GET /cart as a guest creates a cart and returns a token' ($r.Status -eq 200 -and $r.Json.data.is_guest -eq $true -and $null -ne $r.Json.data.cart_token -and $GuestToken -eq $r.Json.data.cart_token)
    Check 'Empty guest cart is not checkout-ready' ($r.Json.data.items.Count -eq 0 -and $r.Json.data.checkout_ready -eq $false -and $r.Json.data.checkout_blocker -eq 'CART_EMPTY')

    $r = Api POST '/cart/items' @{ product_id = $Jacket; quantity = 2 } -ExtraHeaders @{ 'X-Cart-Token' = $GuestToken }
    Check 'Guest can add items with X-Cart-Token' ($r.Status -eq 201 -and $r.Json.data.items[0].quantity -eq 2 -and $r.Json.data.is_guest -eq $true)
    $JacketShow = Api GET "/products/$Jacket"
    Check 'Adding as a guest reserves stock without decrementing on-hand' ($JacketShow.Json.data.stock_quantity -eq 35 -and $null -ne $r.Json.data.reservation_expires_at)
    Check 'Storefront available_quantity drops while on-hand stays 35' ($JacketShow.Json.data.available_quantity -eq 33 -and $JacketShow.Json.data.in_stock -eq $true)

    $r = Api GET '/cart' -ExtraHeaders @{ 'X-Cart-Token' = 'not-a-uuid' }
    Check 'Malformed cart token returns 422 CART_TOKEN_INVALID' ($r.Status -eq 422 -and $r.Json.code -eq 'CART_TOKEN_INVALID')

    $r = Api GET '/cart' -ExtraHeaders @{ 'X-Cart-Token' = '00000000-0000-0000-0000-000000000000' }
    Check 'Unknown cart token returns 404 CART_NOT_FOUND' ($r.Status -eq 404 -and $r.Json.code -eq 'CART_NOT_FOUND')

    $r = Api GET '/cart'
    $IdleToken = $r.Json.data.cart_token
    $null = Tinker "App\Models\Cart::query()->where('token', '$IdleToken')->update(['updated_at' => now()->subDays(31)]);"
    $r = Api GET '/cart' -ExtraHeaders @{ 'X-Cart-Token' = $IdleToken }
    Check 'Idle guest cart returns 404 CART_NOT_FOUND' ($r.Status -eq 404 -and $r.Json.code -eq 'CART_NOT_FOUND')
    $r = Api GET '/cart'
    Check 'A new guest cart is issued after idle expiry' ($r.Status -eq 200 -and $r.Json.data.cart_token -ne $IdleToken -and $r.Json.data.checkout_blocker -eq 'CART_EMPTY')
    $null = Api DELETE '/cart' -ExtraHeaders @{ 'X-Cart-Token' = $r.Json.data.cart_token }

    $r = Api POST '/auth/register' @{ name = "Smoke Guest $RunId"; email = "smoke+guest$RunId@example.com"; password = 'Secret123'; password_confirmation = 'Secret123' } -ExtraHeaders @{ 'X-Cart-Token' = $GuestToken }
    Check 'Register with X-Cart-Token adopts the guest cart' ($r.Status -eq 201) "status=$($r.Status) $($r.Raw)"
    $GuestUserToken = $r.Json.data.access_token
    $r = Api GET '/cart' -Token $GuestUserToken
    Check 'Adopted cart is authenticated and still has the guest items' ($r.Status -eq 200 -and $r.Json.data.is_guest -eq $false -and $null -eq $r.Json.data.cart_token -and $r.Json.data.items[0].quantity -eq 2)
    $null = Api DELETE '/cart' -Token $GuestUserToken

    $null = Api DELETE '/cart' -Token $CustomerToken
    $null = Api DELETE '/cart' -Token $AdminToken

    $r = Api GET '/cart' -Token $CustomerToken
    Check 'Empty authenticated cart has zero totals and is not checkout-ready' ($r.Status -eq 200 -and $r.Json.data.items.Count -eq 0 -and $r.Json.data.subtotal -eq '0.00' -and $r.Json.data.is_guest -eq $false -and $r.Json.data.checkout_ready -eq $false -and $r.Json.data.checkout_blocker -eq 'CART_EMPTY')

    # =======================================================================
    Section 'Cart: add items'
    # =======================================================================
    $r = Api POST '/cart/items' @{ product_id = $Sneakers } -Token $CustomerToken
    Check 'Add without quantity defaults to 1 and returns 201' ($r.Status -eq 201 -and $r.Json.data.items[0].quantity -eq 1 -and $null -eq $r.Json.data.checkout_blocker)

    $r = Api POST '/cart/items' @{ product_id = $Sneakers; quantity = 3 } -Token $CustomerToken
    Check 'Adding same product again increases quantity (200, single line)' ($r.Status -eq 200 -and $r.Json.data.items.Count -eq 1 -and $r.Json.data.items[0].quantity -eq 4)
    Check 'Line total and subtotal computed on server (4 x 120.00)' ($r.Json.data.items[0].line_total -eq '480.00' -and $r.Json.data.subtotal -eq '480.00')

    $r = Api POST '/cart/items' @{ product_id = $Sneakers; quantity = 2 } -Token $CustomerToken
    Check 'Exceeding stock including quantity already in cart returns 409' ($r.Status -eq 409 -and $r.Json.code -eq 'INSUFFICIENT_STOCK' -and $r.Json.details.requested_quantity -eq 6 -and $r.Json.details.available_quantity -eq 5)
    Check 'Rejected add leaves the cart unchanged' ((Api GET '/cart' -Token $CustomerToken).Json.data.items[0].quantity -eq 4)

    $r = Api POST '/cart/items' @{ product_id = $Sneakers; quantity = 1 } -Token $CustomerToken
    Check 'Filling exactly to available stock (5) is allowed' ($r.Status -eq 200 -and $r.Json.data.items[0].quantity -eq 5)

    $r = Api POST '/cart/items' @{ product_id = $AirPods; quantity = 1; price = '0.01'; unit_price = '0.01'; subtotal = '0.01' } -Token $CustomerToken
    $airpodsLine = @($r.Json.data.items | Where-Object { $_.product.id -eq $AirPods })[0]
    Check 'Client-sent prices are ignored (server price 249.00 used)' ($r.Status -eq 201 -and $airpodsLine.unit_price -eq '249.00')
    $AirPodsItem = $airpodsLine.id

    $r = Api POST '/cart/items' @{ product_id = $Pixel } -Token $CustomerToken
    Check 'Out-of-stock product returns 409 "is out of stock"' ($r.Status -eq 409 -and $r.Json.message -like '*out of stock*')

    $r = Api POST '/cart/items' @{ product_id = $Poles } -Token $CustomerToken
    Check 'Inactive product returns 422 PRODUCT_UNAVAILABLE' ($r.Status -eq 422 -and $r.Json.code -eq 'PRODUCT_UNAVAILABLE')

    $r = Api POST '/cart/items' @{ product_id = 999999 } -Token $CustomerToken
    Check 'Missing product returns 404 Product not found' ($r.Status -eq 404 -and $r.Json.message -eq 'Product not found.')

    $r = Api POST '/cart/items' @{} -Token $CustomerToken
    Check 'Missing product_id returns 422' ($r.Status -eq 422 -and (Has $r.Json.errors 'product_id'))

    $r = Api POST '/cart/items' @{ product_id = 'abc'; quantity = 0 } -Token $CustomerToken
    Check 'Non-integer product_id and quantity 0 return 422' ($r.Status -eq 422 -and (Has $r.Json.errors 'product_id') -and (Has $r.Json.errors 'quantity'))

    $r = Api POST '/cart/items' @{ product_id = $AirPods; quantity = 1001 } -Token $CustomerToken
    Check 'Quantity above 1000 returns 422' ($r.Status -eq 422 -and (Has $r.Json.errors 'quantity'))

    $r = Api POST '/cart/items' @{ product_id = $AirPods; quantity = -3 } -Token $CustomerToken
    Check 'Negative quantity returns 422' ($r.Status -eq 422 -and (Has $r.Json.errors 'quantity'))

    # =======================================================================
    Section 'Cart: update & remove items'
    # =======================================================================
    $r = Api PATCH "/cart/items/$AirPodsItem" @{ quantity = 3 } -Token $CustomerToken
    Check 'PATCH updates quantity and recalculates totals' ($r.Status -eq 200 -and @($r.Json.data.items | Where-Object { $_.id -eq $AirPodsItem })[0].line_total -eq '747.00' -and $r.Json.data.subtotal -eq '1347.00')

    $r = Api PATCH "/cart/items/$AirPodsItem" @{ quantity = 61 } -Token $CustomerToken
    Check 'PATCH above stock returns 409' ($r.Status -eq 409 -and $r.Json.details.available_quantity -eq 60)

    $r = Api PATCH "/cart/items/$AirPodsItem" @{ quantity = 0 } -Token $CustomerToken
    Check 'PATCH with quantity 0 returns 422 (use DELETE to remove)' ($r.Status -eq 422)

    $r = Api PATCH "/cart/items/$AirPodsItem" @{} -Token $CustomerToken
    Check 'PATCH without quantity returns 422' ($r.Status -eq 422 -and (Has $r.Json.errors 'quantity'))

    $r = Api PATCH '/cart/items/999999' @{ quantity = 1 } -Token $CustomerToken
    Check 'PATCH unknown item returns 404 Cart item not found' ($r.Status -eq 404 -and $r.Json.message -eq 'Cart item not found.')

    $r = Api PATCH '/cart/items/abc' @{ quantity = 1 } -Token $CustomerToken
    Check 'PATCH non-numeric id returns 404' ($r.Status -eq 404)

    $r = Api PATCH "/cart/items/$AirPodsItem" @{ quantity = 1 } -Token $AdminToken
    Check "Another user cannot update someone else's cart item (404)" ($r.Status -eq 404)
    $r = Api DELETE "/cart/items/$AirPodsItem" -Token $AdminToken
    Check "Another user cannot delete someone else's cart item (404)" ($r.Status -eq 404)
    Check "Other user's cart is separate and empty" ((Api GET '/cart' -Token $AdminToken).Json.data.items.Count -eq 0)
    Check 'Owner cart is untouched by the other user' (@((Api GET '/cart' -Token $CustomerToken).Json.data.items | Where-Object { $_.id -eq $AirPodsItem })[0].quantity -eq 3)

    # =======================================================================
    Section 'Cart: catalog changes after items were added'
    # =======================================================================
    $r = Api POST '/cart/items' @{ product_id = $Espresso; quantity = 1 } -Token $CustomerToken
    Check 'Espresso can be added before catalog changes' ($r.Status -eq 201) "status=$($r.Status) $($r.Raw)"
    $EspressoItem = (CartLine $r $Espresso).id
    $r = Api POST '/cart/items' @{ product_id = $Sony; quantity = 1 } -Token $CustomerToken
    Check 'Sony can be added before catalog changes' ($r.Status -eq 201) "status=$($r.Status) $($r.Raw)"
    $SonyItem = (CartLine $r $Sony).id

    $null = Tinker "App\Models\Product::query()->where('sku', 'AUD-APL-APP2')->update(['price' => '199.00']); App\Models\Product::query()->where('sku', 'SNK-LTD-001')->update(['stock_quantity' => 2]); App\Models\Product::query()->where('sku', 'HOM-APP-ESP')->update(['status' => 'inactive']); App\Models\Product::query()->where('sku', 'AUD-SNY-XM5')->delete(); App\Models\Product::query()->where('sku', 'BOK-CLN-ARC')->delete();"

    $r = Api GET '/cart' -Token $CustomerToken
    $airpodsLine = CartLine $r $AirPods
    $sneakersLine = CartLine $r $Sneakers
    $espressoLine = CartLine $r $Espresso
    $sonyLine = CartLine $r $Sony
    Check 'Price change is reflected immediately (AirPods now 199.00)' ($airpodsLine.unit_price -eq '199.00' -and $airpodsLine.issue -eq $null)
    Check 'Stock drop flags insufficient_stock with available quantity' ($sneakersLine.issue -eq 'insufficient_stock' -and $sneakersLine.available_quantity -eq 2)
    Check 'Deactivated product is flagged product_unavailable' ($espressoLine.issue -eq 'product_unavailable' -and $espressoLine.is_purchasable -eq $false) "issue=$($espressoLine.issue) purchasable=$($espressoLine.is_purchasable)"
    Check 'Soft-deleted product stays visible but flagged product_unavailable' ($sonyLine.issue -eq 'product_unavailable' -and $sonyLine.available_quantity -eq 0) "issue=$($sonyLine.issue) available=$($sonyLine.available_quantity)"
    Check 'Subtotal only counts purchasable lines (3 x 199.00 = 597.00)' ($r.Json.data.subtotal -eq '597.00')
    Check 'Cart with issues is not checkout-ready' ($r.Json.data.has_issues -eq $true -and $r.Json.data.checkout_ready -eq $false)

    $r = Api PATCH "/cart/items/$EspressoItem" @{ quantity = 2 } -Token $CustomerToken
    Check 'Updating a discontinued item returns 422 PRODUCT_UNAVAILABLE' ($r.Status -eq 422 -and $r.Json.code -eq 'PRODUCT_UNAVAILABLE')

    $r = Api POST '/cart/items' @{ product_id = $Book } -Token $CustomerToken
    Check 'Adding a soft-deleted product returns 404' ($r.Status -eq 404 -and $r.Json.message -eq 'Product not found.')

    $r = Api GET "/products/$Book"
    Check 'Soft-deleted product is hidden from product details' ($r.Status -eq 404)

    $r = Api PATCH "/cart/items/$(@((Api GET '/cart' -Token $CustomerToken).Json.data.items | Where-Object { $_.product.id -eq $Sneakers })[0].id)" @{ quantity = 2 } -Token $CustomerToken
    Check 'Fixing the quantity clears the insufficient_stock issue' ($r.Status -eq 200 -and @($r.Json.data.items | Where-Object { $_.product.id -eq $Sneakers })[0].issue -eq $null)

    $r = Api DELETE "/cart/items/$EspressoItem" -Token $CustomerToken
    $r = Api DELETE "/cart/items/$SonyItem" -Token $CustomerToken
    Check 'Removing unavailable items makes the cart checkout-ready' ($r.Status -eq 200 -and $r.Json.data.checkout_ready -eq $true -and $r.Json.data.subtotal -eq '837.00')

    $r = Api DELETE "/cart/items/$SonyItem" -Token $CustomerToken
    Check 'Deleting an already removed item returns 404' ($r.Status -eq 404)

    $r = Api DELETE '/cart' -Token $CustomerToken
    Check 'DELETE /cart empties the cart' ($r.Status -eq 200 -and $r.Json.data.items_count -eq 0 -and $r.Json.data.subtotal -eq '0.00')

    # =======================================================================
    Section 'Promotions: guest cart'
    # =======================================================================
    $r = Api GET '/cart'
    $PromoGuestToken = $r.Headers['x-cart-token']
    $r = Api POST '/promotions/validate' @{ code = 'SUMMER20' } -ExtraHeaders @{ 'X-Cart-Token' = $PromoGuestToken }
    Check 'Guest validate with an empty cart returns 422 CART_EMPTY' ($r.Status -eq 422 -and $r.Json.code -eq 'CART_EMPTY')
    $null = Api DELETE '/cart' -ExtraHeaders @{ 'X-Cart-Token' = $PromoGuestToken }

    # Promotion checks are limited to 10 per minute per user, so cases are spread over three users.
    $tokenOutput = Tinker "echo App\Models\User::factory()->create(['email' => 'smoke+promo$RunId@example.com'])->createToken('smoke')->plainTextToken;"
    $PromoToken = ($tokenOutput -split "`n" | Where-Object { $_ -match '^\d+\|' } | Select-Object -First 1).Trim()

    # =======================================================================
    Section 'Promotions: every rejection reason (admin user)'
    # =======================================================================
    $null = Api DELETE '/cart' -Token $AdminToken
    $r = Api POST '/promotions/validate' @{ code = 'SUMMER20' } -Token $AdminToken
    Check 'Validate with empty cart returns 422 CART_EMPTY' ($r.Status -eq 422 -and $r.Json.code -eq 'CART_EMPTY')
    $r = Api POST '/cart/promotion' @{ code = 'SUMMER20' } -Token $AdminToken
    Check 'Apply with empty cart returns 422 CART_EMPTY' ($r.Status -eq 422 -and $r.Json.code -eq 'CART_EMPTY')

    $null = Api POST '/cart/items' @{ product_id = $DataBook; quantity = 2 } -Token $AdminToken
    $r = Api POST '/promotions/validate' @{ code = 'NOPE' } -Token $AdminToken
    Check 'Unknown code returns 422 PROMOTION_INVALID' ($r.Status -eq 422 -and $r.Json.code -eq 'PROMOTION_INVALID' -and $r.Json.message -eq 'The promotion code is invalid.')
    $r = Api POST '/promotions/validate' @{ code = 'DISABLED5' } -Token $AdminToken
    Check 'Inactive code returns 422 PROMOTION_INACTIVE' ($r.Status -eq 422 -and $r.Json.code -eq 'PROMOTION_INACTIVE')
    $r = Api POST '/promotions/validate' @{ code = 'FUTURE25' } -Token $AdminToken
    Check 'Code not started yet returns 422 PROMOTION_NOT_STARTED with starts_at' ($r.Status -eq 422 -and $r.Json.code -eq 'PROMOTION_NOT_STARTED' -and $r.Json.details.starts_at)
    $r = Api POST '/promotions/validate' @{ code = 'EXPIRED15' } -Token $AdminToken
    Check 'Expired code returns 422 PROMOTION_EXPIRED' ($r.Status -eq 422 -and $r.Json.code -eq 'PROMOTION_EXPIRED' -and $r.Json.message -eq 'This promotion code has expired.')
    $r = Api POST '/promotions/validate' @{ code = 'SOLDOUT' } -Token $AdminToken
    Check 'Code at its global usage limit returns 422 PROMOTION_USAGE_LIMIT_REACHED' ($r.Status -eq 422 -and $r.Json.code -eq 'PROMOTION_USAGE_LIMIT_REACHED')
    $r = Api POST '/promotions/validate' @{ code = 'BIGSPENDER' } -Token $AdminToken
    Check 'Fixed code below minimum returns 422 with amount remaining (1000.00 - 91.00)' ($r.Status -eq 422 -and $r.Json.code -eq 'PROMOTION_MINIMUM_NOT_MET' -and $r.Json.details.amount_remaining -eq '909.00')
    $r = Api POST '/promotions/validate' @{ code = 'HALFPRICE' } -Token $AdminToken
    Check 'Uncapped 50% code on 91.00 gives 45.50 off' ($r.Status -eq 200 -and $r.Json.data.eligible -eq $true -and $r.Json.data.discount -eq '45.50' -and $r.Json.data.total -eq '45.50')
    $r = Api GET '/cart' -Token $AdminToken
    Check 'Validating a code does not apply it to the cart' ($r.Json.data.promotion -eq $null -and $r.Json.data.discount -eq '0.00')
    $null = Api DELETE '/cart' -Token $AdminToken

    # =======================================================================
    Section 'Promotions: SUMMER20 example and applying codes (customer)'
    # =======================================================================
    $null = Api DELETE '/cart' -Token $CustomerToken
    $r = Api POST '/cart/items' @{ product_id = $Jacket; quantity = 1 } -Token $CustomerToken
    Check 'Customer can add a jacket to exercise SUMMER20' ($r.Status -eq 201 -and $null -ne $r.Json.data.items) "status=$($r.Status) $($r.Raw)"
    $JacketItem = @($r.Json.data.items)[0].id
    $r = Api POST '/promotions/validate' @{ code = 'SUMMER20' } -Token $CustomerToken
    Check 'SUMMER20 on 79.50 is rejected: minimum 100.00, 20.50 remaining' ($r.Status -eq 422 -and $r.Json.code -eq 'PROMOTION_MINIMUM_NOT_MET' -and $r.Json.details.min_cart_amount -eq '100.00' -and $r.Json.details.amount_remaining -eq '20.50')

    $r = Api POST '/cart/items' @{ product_id = $DataBook; quantity = 1 } -Token $CustomerToken
    $BookItem = @($r.Json.data.items | Where-Object { $_.product.id -eq $DataBook })[0].id
    $r = Api POST '/promotions/validate' @{ code = 'SUMMER20' } -Token $CustomerToken
    Check 'SUMMER20 on 125.00 gives 20% = 25.00 off' ($r.Status -eq 200 -and $r.Json.data.subtotal -eq '125.00' -and $r.Json.data.discount -eq '25.00' -and $r.Json.data.total -eq '100.00')

    $null = Api PATCH "/cart/items/$JacketItem" @{ quantity = 3 } -Token $CustomerToken
    $r = Api POST '/promotions/validate' @{ code = '  summer20 ' } -Token $CustomerToken
    Check 'SUMMER20 on 284.00 is capped at 50.00 (20% would be 56.80)' ($r.Status -eq 200 -and $r.Json.data.subtotal -eq '284.00' -and $r.Json.data.discount -eq '50.00' -and $r.Json.data.total -eq '234.00')
    Check 'Code is case-insensitive and trimmed' ($r.Json.data.code -eq 'SUMMER20')

    $r = Api POST '/promotions/validate' @{ code = 'ONCEONLY' } -Token $CustomerToken
    Check 'Code already used by this customer returns 422 PROMOTION_CUSTOMER_LIMIT_REACHED' ($r.Status -eq 422 -and $r.Json.code -eq 'PROMOTION_CUSTOMER_LIMIT_REACHED' -and $r.Json.details.per_customer_limit -eq 1 -and $r.Json.details.times_used -eq 1)

    $r = Api POST '/cart/promotion' @{ code = 'SUMMER20' } -Token $CustomerToken
    Check 'Apply SUMMER20: cart shows promotion, discount 50.00, total 234.00' ($r.Status -eq 200 -and $r.Json.data.promotion.code -eq 'SUMMER20' -and $r.Json.data.promotion.is_valid -eq $true -and $r.Json.data.discount -eq '50.00' -and $r.Json.data.total -eq '234.00' -and $r.Json.data.checkout_ready -eq $true)

    $r = Api POST '/cart/promotion' @{ code = 'EXPIRED15' } -Token $CustomerToken
    Check 'Applying an invalid code fails with 422...' ($r.Status -eq 422 -and $r.Json.code -eq 'PROMOTION_EXPIRED')
    Check '...and keeps the previously applied code' ((Api GET '/cart' -Token $CustomerToken).Json.data.promotion.code -eq 'SUMMER20')

    $r = Api POST '/cart/promotion' @{ code = 'WELCOME10' } -Token $CustomerToken
    Check 'Applying another code replaces it (WELCOME10: fixed 10.00 off)' ($r.Status -eq 200 -and $r.Json.data.promotion.code -eq 'WELCOME10' -and $r.Json.data.discount -eq '10.00' -and $r.Json.data.total -eq '274.00')

    $null = Api POST '/cart/promotion' @{ code = 'SUMMER20' } -Token $CustomerToken
    $null = Api DELETE "/cart/items/$BookItem" -Token $CustomerToken
    $r = Api PATCH "/cart/items/$JacketItem" @{ quantity = 1 } -Token $CustomerToken
    Check 'Shrinking the cart below the minimum invalidates the applied code' ($r.Json.data.promotion.code -eq 'SUMMER20' -and $r.Json.data.promotion.is_valid -eq $false -and $r.Json.data.promotion.error.code -eq 'PROMOTION_MINIMUM_NOT_MET')
    Check 'Invalid applied code gives no discount and blocks checkout' ($r.Json.data.discount -eq '0.00' -and $r.Json.data.total -eq '79.50' -and $r.Json.data.checkout_ready -eq $false -and $r.Json.data.checkout_blocker -eq 'PROMOTION_MINIMUM_NOT_MET')

    $r = Api DELETE '/cart/promotion' -Token $CustomerToken
    Check 'DELETE /cart/promotion removes the code and unblocks checkout' ($r.Status -eq 200 -and $r.Json.data.promotion -eq $null -and $r.Json.data.checkout_ready -eq $true)

    $null = Api POST '/cart/promotion' @{ code = 'WELCOME10' } -Token $CustomerToken
    $r = Api DELETE '/cart' -Token $CustomerToken
    Check 'Clearing the cart also removes the applied code' ($r.Json.data.promotion -eq $null)

    # =======================================================================
    Section 'Promotions: per-customer isolation, validation and rate limiting (new user)'
    # =======================================================================
    $null = Api POST '/cart/items' @{ product_id = $Jacket; quantity = 1 } -Token $PromoToken
    $r = Api POST '/promotions/validate' @{ code = 'ONCEONLY' } -Token $PromoToken
    Check 'Per-customer limit only applies to the customer who used it (ONCEONLY valid here)' ($r.Status -eq 200 -and $r.Json.data.discount -eq '5.00')
    $r = Api POST '/promotions/validate' @{} -Token $PromoToken
    Check 'Missing code returns 422 validation error' ($r.Status -eq 422 -and (Has $r.Json.errors 'code'))
    for ($i = 3; $i -le 10; $i++) { $null = Api POST '/promotions/validate' @{ code = "GUESS$i" } -Token $PromoToken }
    $r = Api POST '/promotions/validate' @{ code = 'GUESS11' } -Token $PromoToken
    Check '11th code check in a minute returns 429 (brute-force protection)' ($r.Status -eq 429 -and $r.Json.code -eq 'TOO_MANY_REQUESTS')

    # =======================================================================
    Section 'Promotions: product and category scope'
    # =======================================================================
    $null = RestoreSeededCatalog
    $tokenOutput = Tinker "echo App\Models\User::factory()->create(['email' => 'smoke+scope$RunId@example.com'])->createToken('smoke')->plainTextToken;"
    $ScopeToken = ($tokenOutput -split "`n" | Where-Object { $_ -match '^\d+\|' } | Select-Object -First 1).Trim()

    $null = Api POST '/cart/items' @{ product_id = $AirPods; quantity = 1 } -Token $ScopeToken
    $null = Api POST '/cart/items' @{ product_id = $Jacket; quantity = 1 } -Token $ScopeToken
    $r = Api POST '/promotions/validate' @{ code = 'AUDIO10' } -Token $ScopeToken
    Check 'AUDIO10 discounts 10% of AirPods only (249.00 -> 24.90), jacket stays full price' ($r.Status -eq 200 -and $r.Json.data.scope -eq 'categories' -and $r.Json.data.subtotal -eq '328.50' -and $r.Json.data.eligible_subtotal -eq '249.00' -and $r.Json.data.discount -eq '24.90' -and $r.Json.data.total -eq '303.60') "status=$($r.Status) $($r.Raw)"

    $r = Api POST '/cart/promotion' @{ code = 'AUDIO10' } -Token $ScopeToken
    $airpodsLine = @($r.Json.data.items | Where-Object { $_.product.id -eq $AirPods })[0]
    $jacketLine = @($r.Json.data.items | Where-Object { $_.product.id -eq $Jacket })[0]
    Check 'Eligible cart line is flagged and carries the discount' ($airpodsLine.promotion_eligible -eq $true -and $airpodsLine.discount -eq '24.90')
    Check 'Ineligible cart line is not discounted' ($jacketLine.promotion_eligible -eq $false -and $jacketLine.discount -eq '0.00')

    $null = Api DELETE '/cart' -Token $ScopeToken
    $null = Api POST '/cart/items' @{ product_id = $Jacket; quantity = 1 } -Token $ScopeToken
    $r = Api POST '/promotions/validate' @{ code = 'AUDIO10' } -Token $ScopeToken
    Check 'Category-scoped code with no matching items returns 422 PROMOTION_NOT_APPLICABLE' ($r.Status -eq 422 -and $r.Json.code -eq 'PROMOTION_NOT_APPLICABLE')

    $null = Api DELETE '/cart' -Token $ScopeToken
    $null = Api POST '/cart/items' @{ product_id = $Sneakers; quantity = 1 } -Token $ScopeToken
    $null = Api POST '/cart/items' @{ product_id = $Jacket; quantity = 1 } -Token $ScopeToken
    $r = Api POST '/promotions/validate' @{ code = 'SNKDEAL' } -Token $ScopeToken
    Check 'SNKDEAL takes $20 off sneakers only (subtotal 199.50, total 179.50)' ($r.Status -eq 200 -and $r.Json.data.scope -eq 'products' -and $r.Json.data.eligible_subtotal -eq '120.00' -and $r.Json.data.discount -eq '20.00' -and $r.Json.data.total -eq '179.50') "status=$($r.Status) $($r.Raw)"
    $null = Api DELETE '/cart' -Token $ScopeToken

    # =======================================================================
    Section 'Checkout and orders'
    # =======================================================================
    $null = RestoreSeededCatalog
    $null = Api DELETE '/cart' -Token $CustomerToken
    $null = Api DELETE '/cart' -Token $AdminToken

    $r = Api POST '/checkout' $null
    Check 'Checkout without a token returns 401' ($r.Status -eq 401 -and $r.Json.code -eq 'UNAUTHENTICATED')
    $r = Api GET '/orders' $null
    Check 'Order list without a token returns 401' ($r.Status -eq 401)

    $r = Api POST '/checkout' (CheckoutBody) $CustomerToken
    Check 'Empty cart checkout returns 422 CART_EMPTY' ($r.Status -eq 422 -and $r.Json.code -eq 'CART_EMPTY')
    $missingQuote = CheckoutBody
    $missingQuote.Remove('expected_total')
    $r = Api POST '/checkout' $missingQuote $CustomerToken
    Check 'Checkout without expected_total returns 422 VALIDATION_FAILED' ($r.Status -eq 422 -and $r.Json.code -eq 'VALIDATION_FAILED' -and (Has $r.Json.errors 'expected_total')) "status=$($r.Status) $($r.Raw)"

    $bookStockBefore = (Api GET "/products/$DataBook").Json.data.stock_quantity
    $null = Api POST '/cart/items' @{ product_id = $DataBook; quantity = 2 } -Token $CustomerToken
    $quotedBookTotal = CartTotal $CustomerToken
    $null = Tinker "App\Models\Product::query()->whereKey($DataBook)->update(['price' => '999.00']);"
    $r = Api POST '/checkout' (CheckoutBody @{ expected_total = $quotedBookTotal }) $CustomerToken
    Check 'Stale expected_total returns 409 PRICE_CHANGED' ($r.Status -eq 409 -and $r.Json.code -eq 'PRICE_CHANGED' -and $r.Json.details.expected_total -eq $quotedBookTotal) "status=$($r.Status) $($r.Raw)"
    $cartAfterPriceChange = Api GET '/cart' -Token $CustomerToken
    Check 'PRICE_CHANGED leaves the cart and stock unchanged' ($cartAfterPriceChange.Json.data.items_count -eq 2 -and (Api GET "/products/$DataBook").Json.data.stock_quantity -eq $bookStockBefore) "cart=$($cartAfterPriceChange.Json.data.items_count) stock=$((Api GET "/products/$DataBook").Json.data.stock_quantity)"
    $null = Tinker "App\Models\Product::query()->whereKey($DataBook)->update(['price' => '45.50']);"
    $r = Api POST '/checkout' (CheckoutQuoted $CustomerToken) $CustomerToken
    Check 'Unpaid pending checkout commits stock immediately' ($r.Status -eq 201 -and $r.Json.data.status -eq 'pending' -and $null -ne $r.Json.data.payment_due_at) "status=$($r.Status) $($r.Raw)"
    $HeldOrderId = $r.Json.data.id
    Check 'Unpaid pending order holds on-hand stock until cancelled or expired' ((Api GET "/products/$DataBook").Json.data.stock_quantity -eq ($bookStockBefore - 2))
    $expireFresh = Artisan 'orders:expire-pending'
    Check 'Fresh pending orders are not auto-expired' ($expireFresh -match 'Expired 0 unpaid pending order\(s\)\.') "out=$expireFresh"
    Check 'Held stock stays committed before the payment window elapses' ((Api GET "/orders/$HeldOrderId" -Token $CustomerToken).Json.data.status -eq 'pending')
    $null = Tinker "App\Models\Order::query()->whereKey($HeldOrderId)->update(['payment_due_at' => now()->subMinute()]);"
    $expireStale = Artisan 'orders:expire-pending'
    Check 'Abandoned pending orders are expired by the scheduler command' ($expireStale -match 'Expired 1 unpaid pending order\(s\)\.') "out=$expireStale"
    $expired = Api GET "/orders/$HeldOrderId" -Token $CustomerToken
    Check 'Expired order is cancelled and no longer payable' ($expired.Status -eq 200 -and $expired.Json.data.status -eq 'cancelled' -and $expired.Json.data.payable -eq $false -and @($expired.Json.data.status_history)[-1].source -eq 'expiry')
    Check 'Expiry restores the held stock' ((Api GET "/products/$DataBook").Json.data.stock_quantity -eq $bookStockBefore)
    $expiryLogs = AssertStatusChangedLog 'Expiry logs order status changed (pending -> cancelled, source expiry)' $HeldOrderId 'pending' 'cancelled' 'expiry'
    $expireAgain = Artisan 'orders:expire-pending'
    Check 'Expiry is idempotent and does not restore stock twice' ($expireAgain -match 'Expired 0 unpaid pending order\(s\)\.' -and (Api GET "/products/$DataBook").Json.data.stock_quantity -eq $bookStockBefore) "out=$expireAgain"
    AssertNoNewStatusChangedLog 'Idempotent expiry does not log a second status change' $HeldOrderId 'pending' 'cancelled' 'expiry' $expiryLogs

    $jacketStockBefore = (Api GET "/products/$Jacket").Json.data.stock_quantity
    $null = Api POST '/cart/items' @{ product_id = $Jacket; quantity = 1 } -Token $CustomerToken
    $null = Tinker "App\Models\Cart::query()->where('user_id', App\Models\User::query()->where('email', 'customer@example.com')->value('id'))->update(['promotion_id' => App\Models\Promotion::query()->where('code', 'SUMMER20')->value('id')]);"
    $r = Api POST '/checkout' (CheckoutBody) $CustomerToken
    Check 'Checkout with an invalid applied code returns 422 PROMOTION_MINIMUM_NOT_MET' ($r.Status -eq 422 -and $r.Json.code -eq 'PROMOTION_MINIMUM_NOT_MET')
    $cartAfterFail = Api GET '/cart' -Token $CustomerToken
    $jacketAfterFail = Api GET "/products/$Jacket"
    Check 'Failed checkout leaves the cart and stock unchanged' ($cartAfterFail.Json.data.items_count -eq 1 -and $jacketAfterFail.Json.data.stock_quantity -eq $jacketStockBefore) "cart=$($cartAfterFail.Json.data.items_count) jacket_stock=$($jacketAfterFail.Json.data.stock_quantity) before=$jacketStockBefore"

    $null = Api DELETE '/cart' -Token $CustomerToken
    $null = Api DELETE '/cart' -Token $AdminToken
    $null = Api POST '/cart/items' @{ product_id = $Sneakers; quantity = 4 } -Token $CustomerToken
    $r = Api POST '/cart/items' @{ product_id = $Sneakers; quantity = 3 } -Token $AdminToken
    Check 'Second buyer cannot reserve 3 sneakers after the first reserved 4 of 5' ($r.Status -eq 409 -and $r.Json.code -eq 'INSUFFICIENT_STOCK' -and $r.Json.details.available_quantity -eq 1)
    Check 'On-hand stock stays at 5 while the first cart holds the reservation' ((Api GET "/products/$Sneakers").Json.data.stock_quantity -eq 5)
    $null = Tinker "App\Models\Cart::query()->where('user_id', App\Models\User::query()->where('email', 'customer@example.com')->value('id'))->update(['promotion_id' => App\Models\Promotion::query()->where('code', 'SUMMER20')->value('id')]);"
    $r = Api POST '/checkout' (CheckoutQuoted $CustomerToken @{ subtotal = '0.01'; total = '0.01'; price = '0.01' }) $CustomerToken -ExtraHeaders @{ 'Idempotency-Key' = "smoke-checkout-$RunId" }
    $placed = $r.Status -eq 201 -and $r.Json.data.subtotal -eq '480.00' -and $r.Json.data.discount -eq '50.00' -and $r.Json.data.total -eq '430.00' -and $r.Json.data.status -eq 'pending'
    Check 'Checkout of 4 sneakers with SUMMER20 returns 201 and ignores client totals' $placed "status=$($r.Status) $($r.Raw)"
    $item = @($r.Json.data.items)[0]
    Check 'Order items snapshot the catalog name, SKU and price' ($null -ne $item -and $item.product_name -eq 'Limited Edition Sneakers' -and $item.product_sku -eq 'SNK-LTD-001' -and $item.unit_price -eq '120.00' -and $item.quantity -eq 4)
    Check 'Order snapshots shipping address at checkout' ($r.Json.data.shipping.name -eq 'Maya Daher' -and $r.Json.data.shipping.phone -eq '+961 70 123 456' -and $r.Json.data.shipping.address_line1 -eq 'Hamra St., Building 12, Floor 3' -and $r.Json.data.shipping.city -eq 'Beirut' -and $r.Json.data.shipping.country -eq 'Lebanon')
    $OrderId = $r.Json.data.id
    $OrderNumber = $r.Json.data.order_number
    $prefill = Api GET '/checkout/shipping' -Token $CustomerToken
    Check 'Checkout shipping prefills from the latest order' ($prefill.Status -eq 200 -and $prefill.Json.data.shipping.name -eq 'Maya Daher' -and $prefill.Json.data.shipping.phone -eq '+961 70 123 456' -and $prefill.Json.data.shipping.address_line1 -eq 'Hamra St., Building 12, Floor 3' -and $prefill.Json.data.shipping.city -eq 'Beirut' -and $prefill.Json.data.shipping.country -eq 'Lebanon') "status=$($prefill.Status) $($prefill.Raw)"
    $anonShipping = Api GET '/checkout/shipping'
    Check 'Checkout shipping without a token returns 401' ($anonShipping.Status -eq 401)
    Check 'Successful checkout empties the cart' ((Api GET '/cart' -Token $CustomerToken).Json.data.items_count -eq 0)
    Check 'Successful checkout reduces stock from 5 to 1' ((Api GET "/products/$Sneakers").Json.data.stock_quantity -eq 1)

    $r = Api POST '/checkout' (CheckoutBody @{ total = '0.01' }) $CustomerToken -ExtraHeaders @{ 'Idempotency-Key' = "smoke-checkout-$RunId" }
    Check 'Retry with the same Idempotency-Key returns the original order (200)' ($r.Status -eq 200 -and $r.Json.data.id -eq $OrderId -and $r.Json.data.total -eq '430.00')
    Check 'Idempotent retry does not reduce stock again' ((Api GET "/products/$Sneakers").Json.data.stock_quantity -eq 1)

    $r = Api POST '/cart/items' @{ product_id = $Sneakers; quantity = 3 } -Token $AdminToken
    Check 'Second buyer still cannot reserve 3 of the remaining 1 after checkout' ($r.Status -eq 409 -and $r.Json.code -eq 'INSUFFICIENT_STOCK' -and $r.Json.details.available_quantity -eq 1)
    Check 'Rejected add leaves stock at 1 (never negative)' ((Api GET "/products/$Sneakers").Json.data.stock_quantity -eq 1)

    $null = Tinker "App\Models\Product::find($Sneakers)->update(['price' => '999.00', 'name' => 'Renamed Sneakers']);"
    $r = Api GET "/orders/$OrderId" -Token $CustomerToken
    Check 'GET /orders/{id} returns the placed order' ($r.Status -eq 200 -and $r.Json.data.order_number -eq $OrderNumber)
    Check 'Historical order keeps the original name and price after catalog changes' ($r.Json.data.items[0].product_name -eq 'Limited Edition Sneakers' -and $r.Json.data.items[0].unit_price -eq '120.00' -and $r.Json.data.total -eq '430.00')

    $r = Api GET '/orders' -Token $CustomerToken
    Check 'GET /orders lists the customer order' ($r.Status -eq 200 -and @($r.Json.data | Where-Object { $_.id -eq $OrderId }).Count -eq 1)

    $r = Api GET "/orders/$OrderId" -Token $PromoToken
    Check 'Another customer cannot view this order (403)' ($r.Status -eq 403 -and $r.Json.code -eq 'FORBIDDEN')
    $r = Api GET '/orders/999999' -Token $CustomerToken
    Check 'Missing order returns 404 Order not found' ($r.Status -eq 404 -and $r.Json.message -eq 'Order not found.')
    $r = Api GET "/orders/$OrderId" -Token $AdminToken
    Check 'Admin can view another customer order' ($r.Status -eq 200 -and $r.Json.data.id -eq $OrderId)

    # =======================================================================
    Section 'Order lifecycle'
    # =======================================================================
    $r = Api GET '/orders?status=pending' -Token $CustomerToken
    Check 'Customer can filter their orders by pending' ($r.Status -eq 200 -and @($r.Json.data | Where-Object { $_.id -eq $OrderId }).Count -eq 1)
    $r = Api GET '/orders?status=delivered' -Token $CustomerToken
    Check 'Customer status=delivered omits the pending checkout' ($r.Status -eq 200 -and @($r.Json.data | Where-Object { $_.id -eq $OrderId }).Count -eq 0)
    $r = Api GET '/orders?status=banana' -Token $CustomerToken
    Check 'Customer invalid status filter returns 422' ($r.Status -eq 422 -and (Has $r.Json.errors 'status'))

    $r = Api GET "/orders/$OrderId" -Token $CustomerToken
    Check 'Placed order is pending, payable and has checkout history' ($r.Status -eq 200 -and $r.Json.data.payable -eq $true -and $r.Json.data.status_history[0].to_status -eq 'pending' -and $r.Json.data.status_history[0].source -eq 'checkout' -and $null -eq $r.Json.data.paid_at)

    $r = Api POST "/orders/$OrderId/pay"
    Check 'Pay without a token returns 401' ($r.Status -eq 401 -and $r.Json.code -eq 'UNAUTHENTICATED')
    $r = Api POST "/orders/$OrderId/pay" $null $PromoToken
    Check 'Another customer cannot capture payment (403)' ($r.Status -eq 403 -and $r.Json.code -eq 'FORBIDDEN')
    $r = Api POST "/orders/$OrderId/pay" $null $AdminToken
    Check 'Admin cannot capture payment for another customer (403)' ($r.Status -eq 403 -and $r.Json.code -eq 'FORBIDDEN')

    # =======================================================================
    Section 'Admin orders'
    # =======================================================================
    $r = Api GET '/admin/orders'
    Check 'Guest admin order list returns 401' ($r.Status -eq 401 -and $r.Json.code -eq 'UNAUTHENTICATED')

    $r = Api GET '/admin/orders' -Token $CustomerToken
    Check 'Customer cannot list all orders (403)' ($r.Status -eq 403 -and $r.Json.code -eq 'FORBIDDEN')

    $r = Api GET '/admin/orders' -Token $AdminToken
    Check 'Admin lists every order including this checkout' ($r.Status -eq 200 -and @($r.Json.data | Where-Object { $_.id -eq $OrderId }).Count -eq 1)
    $listed = @($r.Json.data | Where-Object { $_.id -eq $OrderId })[0]
    Check 'Admin order list embeds the customer' ($null -ne $listed -and $listed.customer.email -eq 'customer@example.com' -and $listed.status -eq 'pending')

    $r = Api GET '/admin/orders?status=pending' -Token $AdminToken
    Check 'Admin can filter orders by pending status' ($r.Status -eq 200 -and @($r.Json.data | Where-Object { $_.id -eq $OrderId }).Count -eq 1)
    $r = Api GET '/admin/orders?status=delivered' -Token $AdminToken
    Check 'Admin status=delivered omits the pending checkout' ($r.Status -eq 200 -and @($r.Json.data | Where-Object { $_.id -eq $OrderId }).Count -eq 0)

    $r = Api GET "/admin/orders?search=$OrderNumber" -Token $AdminToken
    Check 'Admin can search orders by order number' ($r.Status -eq 200 -and @($r.Json.data | Where-Object { $_.id -eq $OrderId }).Count -eq 1)

    $r = Api GET '/admin/orders?search=customer@example.com' -Token $AdminToken
    Check 'Admin can search orders by customer email' ($r.Status -eq 200 -and @($r.Json.data | Where-Object { $_.id -eq $OrderId }).Count -eq 1)

    $r = Api GET "/admin/orders/$OrderId" -Token $AdminToken
    Check 'Admin show includes customer, items and totals' ($r.Status -eq 200 -and $r.Json.data.customer.email -eq 'customer@example.com' -and $r.Json.data.items[0].product_sku -eq 'SNK-LTD-001' -and $r.Json.data.total -eq '430.00')
    $CustomerId = $r.Json.data.customer.id
    $r = Api GET "/admin/orders?user_id=$CustomerId" -Token $AdminToken
    Check 'Admin can filter orders by user_id' ($r.Status -eq 200 -and @($r.Json.data | Where-Object { $_.id -eq $OrderId }).Count -eq 1)

    $r = Api PATCH "/admin/orders/$OrderId" @{ status = 'shipped' } $AdminToken
    Check 'Admin cannot skip pending to shipped (422 INVALID_ORDER_TRANSITION)' ($r.Status -eq 422 -and $r.Json.code -eq 'INVALID_ORDER_TRANSITION' -and $r.Json.details.current_status -eq 'pending' -and @($r.Json.details.allowed_statuses)[0] -eq 'paid')

    $r = Api PATCH "/admin/orders/$OrderId" @{ status = 'cancelled' } $AdminToken
    Check 'Admin cannot cancel through the status endpoint (422)' ($r.Status -eq 422 -and (Has $r.Json.errors 'status'))
    Check 'Rejected cancel-via-PATCH leaves the order pending' ((Api GET "/admin/orders/$OrderId" -Token $AdminToken).Json.data.status -eq 'pending')

    $r = Api POST "/orders/$OrderId/pay" $null $CustomerToken
    Check 'Customer can capture payment on a pending order' ($r.Status -eq 200 -and $r.Json.message -eq 'Payment captured.' -and $r.Json.data.status -eq 'paid' -and $r.Json.data.payable -eq $false -and $r.Json.data.cancellable -eq $true -and $null -ne $r.Json.data.paid_at)
    $history = @($r.Json.data.status_history)
    Check 'Payment appends a payment history row after checkout' ($history.Count -eq 2 -and $history[1].to_status -eq 'paid' -and $history[1].source -eq 'payment' -and $history[0].source -eq 'checkout')
    Check 'Capturing payment leaves stock at 1' ((Api GET "/products/$Sneakers").Json.data.stock_quantity -eq 1)
    $payLogs = AssertStatusChangedLog 'Pay logs order status changed (pending -> paid, source payment)' $OrderId 'pending' 'paid' 'payment'

    $r = Api POST "/orders/$OrderId/pay" $null $CustomerToken
    Check 'Retrying pay returns 200 and does not stamp paid_at again' ($r.Status -eq 200 -and $r.Json.message -eq 'Order already paid.' -and $r.Json.data.status -eq 'paid')
    AssertNoNewStatusChangedLog 'Retrying pay does not log a second status change' $OrderId 'pending' 'paid' 'payment' $payLogs

    $r = Api GET '/orders?status=paid' -Token $CustomerToken
    Check 'Customer can filter their orders by paid' ($r.Status -eq 200 -and @($r.Json.data | Where-Object { $_.id -eq $OrderId }).Count -eq 1)

    $r = Api PATCH "/admin/orders/$OrderId" @{ status = 'paid' } $AdminToken
    Check 'Admin repeating paid after capture returns 422 INVALID_ORDER_TRANSITION' ($r.Status -eq 422 -and $r.Json.code -eq 'INVALID_ORDER_TRANSITION' -and $r.Json.details.current_status -eq 'paid')

    # =======================================================================
    Section 'Order cancellation'
    # =======================================================================
    $r = Api POST "/orders/$OrderId/cancel"
    Check 'Cancel without a token returns 401' ($r.Status -eq 401 -and $r.Json.code -eq 'UNAUTHENTICATED')

    $r = Api POST "/orders/$OrderId/cancel" $null $PromoToken
    Check 'Another customer cannot cancel this order (403)' ($r.Status -eq 403 -and $r.Json.code -eq 'FORBIDDEN')

    $r = Api POST "/orders/$OrderId/cancel" $null $AdminToken
    Check 'Admin cannot cancel another customer order (403)' ($r.Status -eq 403 -and $r.Json.code -eq 'FORBIDDEN')

    $r = Api POST "/orders/$OrderId/cancel" $null $CustomerToken
    Check 'Customer can cancel a paid order' ($r.Status -eq 200 -and $r.Json.message -eq 'Order cancelled.' -and $r.Json.data.status -eq 'cancelled' -and $r.Json.data.cancellable -eq $false -and $r.Json.data.payable -eq $false -and $null -ne $r.Json.data.cancelled_at)
    Check 'Cancel appends a cancel history row' (@($r.Json.data.status_history)[-1].to_status -eq 'cancelled' -and @($r.Json.data.status_history)[-1].source -eq 'cancel')
    Check 'Cancellation restores stock from 1 to 5' ((Api GET "/products/$Sneakers").Json.data.stock_quantity -eq 5)
    $cancelLogs = AssertStatusChangedLog 'Cancel logs order status changed (paid -> cancelled, source cancel)' $OrderId 'paid' 'cancelled' 'cancel'

    $promoUsed = (Tinker "echo json_encode(['used' => (int) App\Models\Promotion::query()->where('code', 'SUMMER20')->value('used_count')]);" | Select-String -Pattern '\{.*\}').Matches[0].Value | ConvertFrom-Json
    Check 'Cancellation releases SUMMER20 so used_count returns to 0' ($promoUsed.used -eq 0)

    $r = Api POST "/orders/$OrderId/cancel" $null $CustomerToken
    Check 'Retrying cancel returns 200 and does not restore stock again' ($r.Status -eq 200 -and $r.Json.message -eq 'Order already cancelled.' -and $r.Json.data.status -eq 'cancelled')
    Check 'Idempotent cancel leaves stock at 5' ((Api GET "/products/$Sneakers").Json.data.stock_quantity -eq 5)
    AssertNoNewStatusChangedLog 'Retrying cancel does not log a second status change' $OrderId 'paid' 'cancelled' 'cancel' $cancelLogs

    $r = Api PATCH "/admin/orders/$OrderId" @{ status = 'paid' } $AdminToken
    Check 'Admin cannot move a cancelled order back to paid' ($r.Status -eq 422 -and $r.Json.code -eq 'INVALID_ORDER_TRANSITION' -and $r.Json.details.current_status -eq 'cancelled' -and @($r.Json.details.allowed_statuses).Count -eq 0)

    $null = Tinker "App\Models\Product::find($Sneakers)->update(['price' => '120.00', 'name' => 'Limited Edition Sneakers']);"
    $r = Api POST '/cart/items' @{ product_id = $Sneakers; quantity = 3 } -Token $AdminToken
    Check 'After cancel, the other buyer can add 3 of the restored 5' ($r.Status -eq 201 -and $r.Json.data.total_quantity -eq 3)
    $r = Api POST '/checkout' (CheckoutQuoted $AdminToken) $AdminToken
    Check 'After cancel, the other buyer can purchase 3 of the restored 5' ($r.Status -eq 201 -and @($r.Json.data.items)[0].quantity -eq 3 -and $r.Json.data.total -eq '360.00') "status=$($r.Status) $($r.Raw)"
    Check 'Rebuy reduces restored stock from 5 to 2' ((Api GET "/products/$Sneakers").Json.data.stock_quantity -eq 2)
    Check 'The other buyer cart is consumed on the later successful checkout' ((Api GET '/cart' -Token $AdminToken).Json.data.items_count -eq 0)

    $shipped = (Tinker "echo json_encode(['id' => App\Models\Order::factory()->shipped()->for(App\Models\User::query()->where('email', 'customer@example.com')->first())->create(['subtotal' => '10.00', 'total' => '10.00'])->id]);" | Select-String -Pattern '\{.*\}').Matches[0].Value | ConvertFrom-Json
    $r = Api POST "/orders/$($shipped.id)/cancel" $null $CustomerToken
    Check 'Shipped order cannot be cancelled (422 INVALID_ORDER_STATUS)' ($r.Status -eq 422 -and $r.Json.code -eq 'INVALID_ORDER_STATUS' -and $r.Json.details.current_status -eq 'shipped')

    $r = Api PATCH "/admin/orders/$($shipped.id)" @{ status = 'delivered' } $AdminToken
    Check 'Admin can mark a shipped order as delivered' ($r.Status -eq 200 -and $r.Json.data.status -eq 'delivered' -and $null -ne $r.Json.data.delivered_at -and $null -ne $r.Json.data.shipped_at)
    $null = AssertStatusChangedLog 'Deliver logs order status changed (shipped -> delivered, source admin)' $shipped.id 'shipped' 'delivered' 'admin'

    $processing = (Tinker "echo json_encode(['id' => App\Models\Order::factory()->processing()->for(App\Models\User::query()->where('email', 'customer@example.com')->first())->create(['subtotal' => '10.00', 'total' => '10.00'])->id]);" | Select-String -Pattern '\{.*\}').Matches[0].Value | ConvertFrom-Json
    $r = Api PATCH "/admin/orders/$($processing.id)" @{ status = 'delivered' } $AdminToken
    Check 'Admin cannot skip processing to delivered' ($r.Status -eq 422 -and $r.Json.code -eq 'INVALID_ORDER_TRANSITION' -and @($r.Json.details.allowed_statuses)[0] -eq 'shipped')
    $r = Api PATCH "/admin/orders/$($processing.id)" @{ status = 'shipped' } $AdminToken
    Check 'Admin can move processing to shipped' ($r.Status -eq 200 -and $r.Json.data.status -eq 'shipped')
    $null = AssertStatusChangedLog 'Ship logs order status changed (processing -> shipped, source admin)' $processing.id 'processing' 'shipped' 'admin'
    $r = Api PATCH "/admin/orders/$($processing.id)" @{ status = 'delivered' } $AdminToken
    Check 'Admin can then move shipped to delivered' ($r.Status -eq 200 -and $r.Json.data.status -eq 'delivered')
    $null = AssertStatusChangedLog 'Second deliver logs order status changed (shipped -> delivered, source admin)' $processing.id 'shipped' 'delivered' 'admin'

    # =======================================================================
    Section 'Admin catalog'
    # =======================================================================
    $r = Api POST '/admin/categories' @{ name = "Smoke Cat $RunId" }
    Check 'Guest admin category create returns 401' ($r.Status -eq 401)

    $r = Api POST '/admin/products' @{ name = 'Nope'; sku = "SMOKE-$RunId"; price = '1.00'; stock_quantity = 1 } $CustomerToken
    Check 'Customer cannot create a product (403)' ($r.Status -eq 403 -and $r.Json.code -eq 'FORBIDDEN')

    $r = Api POST '/admin/categories' @{ name = "Smoke Cat $RunId"; slug = "smoke-cat-$RunId" } $AdminToken
    Check 'Admin can create a category' ($r.Status -eq 201 -and $r.Json.data.slug -eq "smoke-cat-$RunId") "status=$($r.Status) $($r.Raw)"
    $SmokeCategoryId = $r.Json.data.id

    $r = Api POST '/admin/products' @{ name = "Smoke Gadget $RunId"; sku = "smoke-$RunId"; price = '9.99'; stock_quantity = 3; status = 'inactive'; category_id = $SmokeCategoryId } $AdminToken
    Check 'Admin can create an inactive product' ($r.Status -eq 201 -and $r.Json.data.sku -eq "SMOKE-$RunId" -and $r.Json.data.status -eq 'inactive') "status=$($r.Status) $($r.Raw)"
    $SmokeProductId = $r.Json.data.id
    Check 'Inactive admin product is hidden from the storefront' ((Api GET "/products/$SmokeProductId").Status -eq 404)

    $r = Api PATCH "/admin/products/$SmokeProductId" @{ status = 'active' } $AdminToken
    Check 'Admin can activate the product' ($r.Status -eq 200 -and $r.Json.data.status -eq 'active')
    Check 'Activated product appears on the storefront' ((Api GET "/products/$SmokeProductId").Status -eq 200 -and (Api GET "/products/$SmokeProductId").Json.data.name -eq "Smoke Gadget $RunId")

    $r = Api GET "/admin/products/$SmokeProductId/audits"
    Check 'Guest product audits return 401' ($r.Status -eq 401)
    $r = Api GET "/admin/products/$SmokeProductId/audits" -Token $CustomerToken
    Check 'Customer cannot read product audits (403)' ($r.Status -eq 403 -and $r.Json.code -eq 'FORBIDDEN')

    $r = Api PATCH "/admin/products/$SmokeProductId" @{ price = '11.11'; stock_quantity = 8 } $AdminToken
    Check 'Admin can edit price and stock together' ($r.Status -eq 200 -and $r.Json.data.price -eq '11.11' -and $r.Json.data.stock_quantity -eq 8)

    $r = Api GET "/admin/products/$SmokeProductId/audits" -Token $AdminToken
    $auditRows = @($r.Json.data)
    $priceAudit = @($auditRows | Where-Object { $_.field -eq 'price' })[0]
    $stockAudit = @($auditRows | Where-Object { $_.field -eq 'stock_quantity' })[0]
    Check 'Price and stock edits each write an audit row' ($r.Status -eq 200 -and $auditRows.Count -eq 2)
    Check 'Price audit stores old and new amounts' ($null -ne $priceAudit -and $priceAudit.old_value -eq '9.99' -and $priceAudit.new_value -eq '11.11' -and $priceAudit.source -eq 'admin' -and $priceAudit.actor.email -eq 'admin@example.com')
    Check 'Stock audit stores old and new quantities' ($null -ne $stockAudit -and $stockAudit.old_value -eq '3' -and $stockAudit.new_value -eq '8' -and $stockAudit.product_sku -eq "SMOKE-$RunId")

    $r = Api PATCH "/admin/products/$SmokeProductId" @{ name = "Smoke Gadget Renamed $RunId" } $AdminToken
    Check 'Name-only edit does not add an audit row' (@((Api GET "/admin/products/$SmokeProductId/audits" -Token $AdminToken).Json.data).Count -eq 2)

    $r = Api GET '/admin/audits?field=price' -Token $AdminToken
    Check 'Global audit log can filter by field=price' ($r.Status -eq 200 -and @($r.Json.data | Where-Object { $_.product_id -eq $SmokeProductId -and $_.field -eq 'price' }).Count -eq 1)

    $r = Api POST '/admin/promotions' @{ code = "smoke$RunId"; type = 'fixed'; value = '3.00'; min_cart_amount = '10.00' } $AdminToken
    Check 'Admin can create a promotion' ($r.Status -eq 201 -and $r.Json.data.code -eq ("SMOKE$RunId".ToUpper()) -and $r.Json.data.used_count -eq 0) "status=$($r.Status) $($r.Raw)"
    $SmokePromoId = $r.Json.data.id
    $r = Api PATCH "/admin/promotions/$SmokePromoId" @{ value = '4.00' } $AdminToken
    Check 'Admin can change a promotion value' ($r.Status -eq 200 -and $r.Json.data.value -eq '4.00')
    $r = Api GET "/admin/promotions/$SmokePromoId/audits" -Token $AdminToken
    $promoAudit = @($r.Json.data | Where-Object { $_.field -eq 'value' })[0]
    Check 'Promotion value edits write an append-only audit row' ($r.Status -eq 200 -and $null -ne $promoAudit -and $promoAudit.old_value -eq '3.00' -and $promoAudit.new_value -eq '4.00' -and $promoAudit.source -eq 'admin')

    $r = Api POST '/admin/promotions' @{ code = "smokescoped$RunId"; type = 'percentage'; value = '10.00'; scope = 'products'; product_ids = @($SmokeProductId) } $AdminToken
    Check 'Admin can create a product-scoped promotion' ($r.Status -eq 201 -and $r.Json.data.scope -eq 'products' -and @($r.Json.data.product_ids)[0] -eq $SmokeProductId) "status=$($r.Status) $($r.Raw)"
    $SmokeScopedPromoId = $r.Json.data.id

    $r = Api POST '/admin/promotions' @{ code = 'NOSCOPE'; type = 'percentage'; value = '10.00'; scope = 'categories' } $AdminToken
    Check 'Category-scoped promotion without category_ids returns 422' ($r.Status -eq 422 -and (Has $r.Json.errors 'category_ids'))

    $r = Api DELETE "/admin/products/$SmokeProductId" -Token $AdminToken
    Check 'Admin can soft-delete a product' ($r.Status -eq 200) "status=$($r.Status) $($r.Raw)"
    Check 'Deleted product is hidden from the storefront' ((Api GET "/products/$SmokeProductId").Status -eq 404)

    $r = Api POST "/admin/products/$SmokeProductId/restore" -Token $AdminToken
    Check 'Admin can restore a deleted product' ($r.Status -eq 200 -and $r.Json.data.id -eq $SmokeProductId) "status=$($r.Status) $($r.Raw)"

    $null = Api DELETE "/admin/products/$SmokeProductId" -Token $AdminToken
    $r = Api DELETE "/admin/promotions/$SmokePromoId" -Token $AdminToken
    Check 'Admin can soft-delete a promotion' ($r.Status -eq 200)
    Check 'Deleted promotion is hidden from admin show' ((Api GET "/admin/promotions/$SmokePromoId" -Token $AdminToken).Status -eq 404)

    $r = Api POST "/admin/promotions/$SmokePromoId/restore" -Token $AdminToken
    Check 'Admin can restore a deleted promotion' ($r.Status -eq 200 -and $r.Json.data.code -eq ("SMOKE$RunId".ToUpper()) -and $r.Json.data.used_count -eq 0)

    $null = Api DELETE "/admin/promotions/$SmokePromoId" -Token $AdminToken
    $null = Api DELETE "/admin/promotions/$SmokeScopedPromoId" -Token $AdminToken
    $null = Api DELETE "/admin/categories/$SmokeCategoryId" -Token $AdminToken

    # =======================================================================
    Section 'Checkout: rate limiting'
    # =======================================================================
    $tokenOutput = Tinker "echo App\Models\User::factory()->create(['email' => 'smoke+checkoutlimit$RunId@example.com'])->createToken('smoke')->plainTextToken;"
    $CheckoutLimitToken = ($tokenOutput -split "`n" | Where-Object { $_ -match '^\d+\|' } | Select-Object -First 1).Trim()
    $limitedCheckout = $null
    for ($i = 1; $i -le 11; $i++) {
        $limitedCheckout = Api POST '/checkout' $null $CheckoutLimitToken
    }
    Check '11th checkout in a minute returns 429 TOO_MANY_REQUESTS' ($limitedCheckout.Status -eq 429 -and $limitedCheckout.Json.code -eq 'TOO_MANY_REQUESTS' -and $limitedCheckout.Headers.ContainsKey('retry-after'))

    # =======================================================================
    Section 'Auth: rate limiting (runs last)'
    # =======================================================================
    $limited = $null
    for ($i = 1; $i -le 6; $i++) {
        $limited = Api POST '/auth/login' @{ email = "ratelimit$RunId@example.com"; password = 'WrongPass1' }
    }
    Check '6th login attempt in a minute returns 429 with Retry-After' ($limited.Status -eq 429 -and $limited.Json.code -eq 'TOO_MANY_REQUESTS' -and $limited.Headers.ContainsKey('retry-after'))
}
finally {
    Section 'Restoring seeded data'
    $null = RestoreSeededCatalog
    $null = Tinker "App\Models\Product::withTrashed()->where('sku', 'like', 'SMOKE-%')->forceDelete(); App\Models\Promotion::where('code', 'SUMMER20')->update(['used_count' => 0]); App\Models\Promotion::withTrashed()->where('code', 'like', 'SMOKE%')->forceDelete(); App\Models\Category::where('slug', 'like', 'smoke-%')->delete(); App\Models\PromotionUsage::whereNotNull('order_id')->delete(); App\Models\Order::query()->delete(); App\Models\User::where('email', 'like', 'smoke+%@example.com')->each(fn (`$u) => `$u->delete());"
    Write-Host '  Seeded products restored and smoke-test users removed.'
    Remove-Item -Recurse -Force $TempDir -ErrorAction SilentlyContinue
}

Write-Host ''
$color = if ($script:Failed -eq 0) { 'Green' } else { 'Red' }
Write-Host "RESULT: $($script:Passed) passed, $($script:Failed) failed" -ForegroundColor $color
if ($script:Failed -gt 0) {
    $script:Failures | ForEach-Object { Write-Host "  - $_" -ForegroundColor Red }
    exit 1
}
