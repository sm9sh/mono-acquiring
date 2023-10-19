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
