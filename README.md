# php-mono-acquiring
## Максимально проста PHP бібліотека для еквайрінгу [Monobank](https://api.monobank.ua/docs/acquiring.html) без зайвих залежностей.
Документація по REST API [тут](https://api.monobank.ua/docs/acquiring.html)

Для ведення запитів вам знадобиться токен з особистого кабінету [https://fop.monobank.ua/](https://fop.monobank.ua/) або тестовий токен з [https://api.monobank.ua/](https://api.monobank.ua/)

## Install

```bash
composer require sm9sh/mono-acquiring
```

## Requirements

* PHP >=7.4
* ext-json
* ext-curl
* ext-openssl

## Compatibility tests

```bash
composer test
# or without Composer dependencies:
php tests/compatibility.php
```

Тест не виконує HTTP-запитів. API calls замінені stub; ключі для перевірки webhook
генеруються лише в пам'яті. Перевіряються optional/null arguments, token/header
fallback, request shape, справжня signature verification та відсутність deprecations.

Очікуваний результат: `26 checks; 0 unique deprecations`, exit code 0.
Перевірено на PHP 7.4, 8.2 і 8.4. Якщо OpenSSL не знаходить config для створення
тестового ключа, задайте `OPENSSL_CONF` на доступний `openssl.cnf` вашого runtime.
Тест `php://input` перевіряє порожній CLI input; web SAPI ним не перевіряється.