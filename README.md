# E-Commerce Technical Assessment

Laravel API for the e-commerce technical assessment.

## Hello World

`GET /api/hello`

```json
{
    "message": "Hello World"
}
```

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```
