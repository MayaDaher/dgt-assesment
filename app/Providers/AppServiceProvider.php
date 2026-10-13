<?php

namespace App\Providers;

use App\Events\OrderPlaced;
use App\Events\OrderStatusChanged;
use App\Listeners\LogOrderStatusChanged;
use App\Listeners\SendOrderPlacedNotification;
use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion;
use App\Observers\ProductObserver;
use App\Observers\PromotionObserver;
use App\Services\Catalog\CatalogCache;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configurePasswordRules();
        $this->configureRateLimiting();
        $this->invalidateCatalogCacheOnWrite();

        Product::observe(ProductObserver::class);
        Promotion::observe(PromotionObserver::class);

        Event::listen(OrderPlaced::class, SendOrderPlacedNotification::class);
        Event::listen(OrderStatusChanged::class, LogOrderStatusChanged::class);
    }

    private function configurePasswordRules(): void
    {
        Password::defaults(function () {
            $rule = Password::min(8)->letters()->mixedCase()->numbers();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(5)->by('auth:email:'.mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by('auth:ip:'.$request->ip()),
        ]);

        // Slows down guessing of promotion codes.
        RateLimiter::for('promotions', fn (Request $request) => Limit::perMinute(10)
            ->by('promotions:'.($request->user()?->id ?? $request->ip())));

        // Checkout and cancel lock inventory rows; cap retries per customer.
        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(10)
            ->by('checkout:'.($request->user()?->id ?? $request->ip())));
    }

    private function invalidateCatalogCacheOnWrite(): void
    {
        $bump = fn () => app(CatalogCache::class)->bump();

        Product::saved($bump);
        Product::deleted($bump);
        Product::restored($bump);
        Category::saved($bump);
        Category::deleted($bump);
    }
}
