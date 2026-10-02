# Audiomack Scraper (PHP)

PHP API for extracting Audiomack song and album metadata. Chromium is used to load pages, activate the player, and capture signed audio URLs.

## Requirements

- PHP 8.2 or newer with `curl`, `json`, and `zip` extensions enabled
- Composer
- Chromium or Google Chrome, plus a matching ChromeDriver
- ChromeDriver listening on `http://127.0.0.1:4444` by default

## Run

Install dependencies and start the API:

```sh
composer install
chromedriver --port=4444
composer serve
```

Set `BROWSER_BINARY` if ChromeDriver cannot locate the browser executable, and `WEBDRIVER_URL` if ChromeDriver runs at a different address.

The API listens on port 8000:

```sh
curl 'http://127.0.0.1:8000/'
curl --get --data-urlencode 'url=https://audiomack.com/artist/song/example' 'http://127.0.0.1:8000/song'
curl --get --data-urlencode 'url=https://audiomack.com/artist/album/example' --data-urlencode 'track=1' 'http://127.0.0.1:8000/album'
```

Interactive API documentation is available at `http://127.0.0.1:8000/docs`; the OpenAPI document is at `http://127.0.0.1:8000/openapi.json`. Swagger UI assets are loaded from unpkg.com, so the docs page requires internet access in the browser.

`GET /song` returns song metadata and writes the same result to `track_data.json`. `GET /album` returns album metadata; its optional `track` parameter also includes metadata for that album track. All successful API responses include `success`, `execution_time`, and `data`; responses include an `X-Process-Time` header.

The Python implementation is retained as a reference during the PHP migration. Live extraction still depends on Audiomack's current page and player behavior.
