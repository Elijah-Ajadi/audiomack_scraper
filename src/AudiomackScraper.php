<?php
declare(strict_types=1);

namespace Audiomack;

use DateTimeImmutable;
use DateTimeZone;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use RuntimeException;
use Throwable;

final class AudiomackScraper
{
    private const AUDIO_MARKERS = ['.mp3', '.m4a', '.m3u8', '/streaming/', '/hq/'];

    private string $webdriverUrl;

    public function __construct(?string $webdriverUrl = null)
    {
        $this->webdriverUrl = $webdriverUrl
            ?? getenv('WEBDRIVER_URL')
            ?: 'http://127.0.0.1:4444';
    }

    public function extractTrackInfo(string $url): array
    {
        $driver = $this->createDriver(false);

        try {
            $page = $this->loadPage($driver, $url, true, 3_000_000);
            $track = $this->mapSong($page, $url);
        } finally {
            $driver->quit();
        }

        $outputPath = dirname(__DIR__) . '/track_data.json';
        $json = json_encode(
            $track,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json === false || file_put_contents($outputPath, $json . "\n") === false) {
            throw new RuntimeException('Could not write track_data.json.');
        }

        return $track;
    }

    public function extractAlbumInfo(string $url, ?int $selectedTrack = null): array
    {
        if (trim($url) === '') {
            throw new RuntimeException('Album URL is required.');
        }
        if (!str_contains(strtolower($url), '/album/')) {
            throw new RuntimeException('URL must be an Audiomack album URL.');
        }

        $driver = $this->createDriver(true);

        try {
            $page = $this->loadPage($driver, $url, false, 600_000);
            $music = $page['music'];
            $title = $this->cleanOgTitle($page['ogTitle']);
            $albumTitle = str_contains($title, ' by ')
                ? trim(explode(' by ', $title, 2)[0])
                : $title;

            if (str_contains($albumTitle, 'Audiomack') && $page['h1'] !== '') {
                $albumTitle = $page['h1'];
            }

            $artist = $page['musician'];
            if ($artist === '' && str_contains($title, ' by ')) {
                $artist = trim(explode(' by ', $title, 2)[1]);
            }
            $artist = preg_replace('/\s*:\s*Listen on Audiomack.*$/i', '', $artist) ?? $artist;

            $release = $this->formatReleaseDate($page['releaseDate']);
            $trackUrls = $page['trackUrls'];
            if ($trackUrls === [] && isset($music['tracks']) && is_array($music['tracks'])) {
                foreach ($music['tracks'] as $track) {
                    if (!is_array($track)) {
                        continue;
                    }
                    $trackUrl = $this->firstValue($track, ['url', 'canonical_url', 'link']);
                    if ($trackUrl !== '') {
                        $trackUrls[] = $this->resolveUrl($url, (string) $trackUrl);
                    }
                }
                $trackUrls = array_values(array_unique($trackUrls));
            }

            $totalSeconds = $this->albumDuration($page, $music, count($trackUrls));
            $result = [
                'albumId' => $this->albumSlug($url),
                'albumTitle' => $albumTitle,
                'albumArtist' => $this->cleanText($artist),
                'albumFeaturingArtists' => '',
                'albumReleaseDate' => $release[0],
                'albumTotalDuration' => $this->formatDuration($totalSeconds),
                'albumGenre' => $this->cleanText($page['genre']),
                'albumYear' => $release[1],
                'albumImageUrl' => $this->cleanText($page['ogImage']),
                'albumTotalTracks' => count($trackUrls),
                'producer' => $this->cleanText($page['producer']),
                'description' => $page['description'],
            ];

            if ($selectedTrack !== null) {
                if ($selectedTrack < 1) {
                    throw new RuntimeException('Track number must be 1 or greater.');
                }
                if ($selectedTrack > count($trackUrls)) {
                    throw new RuntimeException(
                        "Track {$selectedTrack} not found. Album contains " . count($trackUrls) . ' tracks.'
                    );
                }

                $trackPage = $this->loadPage($driver, $trackUrls[$selectedTrack - 1], true, 3_000_000);
                $result['track'] = $this->mapAlbumTrack($trackPage, $trackUrls[$selectedTrack - 1], $selectedTrack);
            }
        } finally {
            $driver->quit();
        }

        return $result;
    }

    private function createDriver(bool $album): RemoteWebDriver
    {
        $options = new ChromeOptions();
        $arguments = [
            '--headless=new',
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--disable-blink-features=AutomationControlled',
            '--window-size=1440,900',
        ];
        $userAgent = $album
            ? 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
            : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
        $arguments[] = '--user-agent=' . $userAgent;

        $binary = getenv('BROWSER_BINARY');
        if ($binary !== false && $binary !== '') {
            $options->setBinary($binary);
        }
        $options->addArguments($arguments);

        $capabilities = DesiredCapabilities::chrome();
        $capabilities->setCapability(ChromeOptions::CAPABILITY, $options);
        try {
            $driver = RemoteWebDriver::create($this->webdriverUrl, $capabilities, 5_000, 30_000);
            $driver->manage()->timeouts()->pageLoadTimeout(30);
            return $driver;
        } catch (Throwable $error) {
            throw new RuntimeException(
                "Could not connect to ChromeDriver at {$this->webdriverUrl}: {$error->getMessage()}",
                0,
                $error
            );
        }
    }

    private function loadPage(RemoteWebDriver $driver, string $url, bool $play, int $hydrationWait): array
    {
        try {
            $driver->get($url);
        } catch (Throwable $error) {
            if (!str_contains(strtolower($error->getMessage()), 'timeout')) {
                throw $error;
            }
        }

        usleep($hydrationWait);
        if ($play) {
            try {
                $driver->executeScript(<<<'JS'
                    window.__audiomackPlayResponses = [];
                    const originalFetch = window.fetch;
                    window.fetch = (...args) => {
                        const requestUrl = String(args[0]?.url || args[0] || '');
                        return originalFetch.apply(window, args).then(response => {
                            if (requestUrl.includes('/v1/music/play/')) {
                                response.clone().text().then(body => window.__audiomackPlayResponses.push(body));
                            }
                            return response;
                        });
                    };
                    const originalOpen = XMLHttpRequest.prototype.open;
                    XMLHttpRequest.prototype.open = function (method, url, ...args) {
                        this.__audiomackRequestUrl = String(url);
                        return originalOpen.call(this, method, url, ...args);
                    };
                    const originalSend = XMLHttpRequest.prototype.send;
                    XMLHttpRequest.prototype.send = function (...args) {
                        if (this.__audiomackRequestUrl?.includes('/v1/music/play/')) {
                            this.addEventListener('load', () => {
                                const body = this.responseType === 'json'
                                    ? JSON.stringify(this.response)
                                    : this.responseText;
                                window.__audiomackPlayResponses.push(body || '');
                            });
                        }
                        return originalSend.apply(this, args);
                    };
                JS);
                $driver->executeScript(<<<'JS'
                    const notNow = [...document.querySelectorAll('button')]
                        .find(button => button.innerText.trim() === 'Not Now');
                    if (notNow) notNow.click();
                JS);
                $buttons = $driver->findElements(
                    \Facebook\WebDriver\WebDriverBy::cssSelector(
                        'button[data-amlabs-play-button="true"], button.play-pause-button, button[aria-label="Play"], button[aria-label^="Play,"]'
                    )
                );
                if ($buttons !== []) {
                    $buttons[0]->click();
                }
            } catch (Throwable) {
                try {
                    $driver->executeScript(<<<'JS'
                        const button = document.querySelector(
                            'button[data-amlabs-play-button="true"], button.play-pause-button, button[aria-label="Play"], button[aria-label^="Play,"]'
                        );
                        if (button) button.click();
                    JS);
                } catch (Throwable) {
                }
            }
        }

        $streamingUrl = '';
        $attempts = $play ? 60 : 1;
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $streamingUrl = $this->findStreamingUrl($driver);
            if ($streamingUrl !== '') {
                break;
            }
            if ($play) {
                usleep(100_000);
            }
        }

        return $this->readPageData($driver, $streamingUrl);
    }

    private function findStreamingUrl(RemoteWebDriver $driver): string
    {
        try {
            $url = $driver->executeScript(<<<'JS'
                const markers = ['.mp3', '.m4a', '.m3u8', '/streaming/', '/hq/'];
                const matches = value => typeof value === 'string'
                    && value.toLowerCase().includes('audiomack.com')
                    && markers.some(marker => value.toLowerCase().includes(marker));
                const findUrl = value => {
                    if (matches(value)) return value;
                    if (Array.isArray(value)) {
                        for (const item of value) {
                            const result = findUrl(item);
                            if (result) return result;
                        }
                    } else if (value && typeof value === 'object') {
                        for (const item of Object.values(value)) {
                            const result = findUrl(item);
                            if (result) return result;
                        }
                    }
                    return '';
                };
                for (const body of window.__audiomackPlayResponses || []) {
                    try {
                        const result = findUrl(JSON.parse(body));
                        if (result) return result;
                    } catch (_) {}
                }
                return performance.getEntriesByType('resource')
                    .map(entry => entry.name)
                    .find(matches) || '';
            JS);
            return is_string($url) ? $url : '';
        } catch (Throwable) {
        }

        return '';
    }

    private function readPageData(RemoteWebDriver $driver, string $streamingUrl): array
    {
        $data = $driver->executeScript(<<<'JS'
            const clean = value => (value || '').replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
            const meta = selector => document.querySelector(selector)?.content || '';
            let nextData = {};
            try {
                nextData = JSON.parse(document.querySelector('script#__NEXT_DATA__')?.textContent || '{}');
            } catch (_) {}
            const props = nextData?.props?.pageProps || {};
            let music = props.music && typeof props.music === 'object' ? props.music : {};
            if (!Object.keys(music).length) {
                const reduxMusic = props.initialReduxState?.music || {};
                if (reduxMusic.title) music = reduxMusic;
                else music = Object.values(reduxMusic).find(value => value && typeof value === 'object' && value.title) || {};
            }
            const rows = {genre: '', producer: '', releaseDate: ''};
            for (const row of document.querySelectorAll('ul.SinglePageMusicCardInfo li.SinglePageMusicCardInfo-row')) {
                const label = clean(row.querySelector('.SinglePageMusicCardInfo-title')?.innerText).toLowerCase();
                const value = clean(row.querySelector('.SinglePageMusicCardInfo-value')?.innerText);
                if (label.includes('genre')) rows.genre = value;
                else if (label.includes('producer')) rows.producer = value;
                else if (label.includes('release date')) rows.releaseDate = value.replace(/[®™©‡]/g, '').trim();
            }
            const trackUrls = [];
            const seen = new Set();
            const addTrack = value => {
                try {
                    const url = new URL(value, location.href);
                    if (!url.pathname.includes('/song/')) return;
                    if (url.origin !== location.origin && !url.hostname.endsWith('audiomack.com')) return;
                    if (url.search || url.hash) { url.search = ''; url.hash = ''; }
                    if (!seen.has(url.href)) { seen.add(url.href); trackUrls.push(url.href); }
                } catch (_) {}
            };
            for (const item of document.querySelectorAll('meta[property="music:song"]')) addTrack(item.content);
            for (const link of document.querySelectorAll('a[href*="/song/"]')) addTrack(link.href);
            const leaves = [...document.querySelectorAll('body *')]
                .filter(node => node.children.length === 0)
                .map(node => clean(node.textContent))
                .filter(text => /^\d{1,2}:\d{2}$/.test(text));
            return {
                ogTitle: meta('meta[property="og:title"]'),
                ogImage: meta('meta[property="og:image"]'),
                musician: meta('meta[property="music:musician"]'),
                releaseDate: meta('meta[property="music:release_date"]'),
                description: meta('meta[property="og:description"]') || meta('meta[name="description"]'),
                h1: clean(document.querySelector('h1')?.innerText),
                music,
                rows,
                trackUrls,
                html: document.documentElement.outerHTML,
                domDurations: leaves,
                streamingUrl: ''
            };
        JS);

        if (!is_array($data)) {
            throw new RuntimeException('Could not read Audiomack page data.');
        }
        $data['music'] = is_array($data['music'] ?? null) ? $data['music'] : [];
        $data['rows'] = is_array($data['rows'] ?? null) ? $data['rows'] : [];
        $data['trackUrls'] = is_array($data['trackUrls'] ?? null) ? $data['trackUrls'] : [];
        $data['domDurations'] = is_array($data['domDurations'] ?? null) ? $data['domDurations'] : [];
        $data['streamingUrl'] = $streamingUrl;

        return $data;
    }

    private function mapSong(array $page, string $url): array
    {
        $music = $page['music'];
        $artist = (string) $this->firstValue($music, ['artist', 'artistName', 'artist_name']);
        $title = (string) $this->firstValue($music, ['title', 'name']);
        $ogTitle = $this->cleanOgTitle($page['ogTitle'] ?? '');
        $featuring = (string) $this->firstValue($music, ['featuring', 'feat']);

        if ($title === '' && str_contains($ogTitle, ' by ')) {
            $title = trim(explode(' by ', $ogTitle, 2)[0]);
        } elseif ($title === '' && !str_contains($ogTitle, 'Audiomack')) {
            $title = $ogTitle;
        }
        if ($artist === '' && str_contains($ogTitle, ' by ')) {
            $artist = trim(explode(':', explode(' by ', $ogTitle, 2)[1], 2)[0]);
        }
        if ($title === '' || str_contains($title, 'Audiomack')) {
            $title = $page['h1'] !== '' && !str_contains($page['h1'], 'Audiomack') ? $page['h1'] : $title;
        }

        $artist = trim(str_replace('Listen on Audiomack', '', $artist));
        if ($featuring === '' && str_contains($artist, ' & ')) {
            $parts = array_map('trim', explode('&', $artist));
            $artist = array_shift($parts) ?? '';
            $featuring = implode('&', $parts);
        }

        $releaseValue = $page['rows']['releaseDate'] ?? '';
        if ($releaseValue === '') {
            $releaseValue = $this->firstValue($music, ['released', 'uploaded', 'released_at']);
        }
        $release = $this->songReleaseDate($releaseValue);
        $duration = $this->durationSeconds($this->firstValue($music, ['duration']));

        return [
            'title' => $title,
            'artist' => $artist,
            'featuringArtists' => $featuring,
            'duration' => $duration > 0 ? $this->formatDuration($duration) : '3:30',
            'genre' => $this->firstValue($music, ['genre']) ?: ($page['rows']['genre'] ?? ''),
            'producer' => $this->firstValue($music, ['producer', 'credits']) ?: ($page['rows']['producer'] ?? ''),
            'releaseDate' => $release[0],
            'year' => $release[1],
            'trackNumber' => $this->firstValue($music, ['trackNumber', 'track_number']) ?: 1,
            'streamingUrl' => $page['streamingUrl'] ?: ($this->firstValue($music, ['streaming_url']) ?: ''),
            'trackImageUrl' => $this->firstValue($music, ['image', 'cover']) ?: ($page['ogImage'] ?? ''),
        ];
    }

    private function mapAlbumTrack(array $page, string $url, int $number): array
    {
        $music = $page['music'];
        $ogTitle = $this->cleanOgTitle($page['ogTitle'] ?? '');
        $title = (string) $this->firstValue($music, ['title', 'name']);
        $artist = (string) $this->firstValue($music, ['artist', 'artistName', 'artist_name']);

        if ($title === '') {
            $title = str_contains($ogTitle, ' by ') ? trim(explode(' by ', $ogTitle, 2)[0]) : $ogTitle;
        }
        if ($artist === '' && str_contains($ogTitle, ' by ')) {
            $artist = trim(explode(':', explode(' by ', $ogTitle, 2)[1], 2)[0]);
        }
        if ($title === '' || str_contains($title, 'Audiomack')) {
            $title = $page['h1'] !== '' && !str_contains($page['h1'], 'Audiomack') ? $page['h1'] : $title;
        }

        $featuring = (string) $this->firstValue($music, ['featuring', 'feat', 'featuredArtists', 'featured_artists']);
        if ($featuring === '' && str_contains($artist, ' & ')) {
            $parts = array_values(array_filter(array_map('trim', explode('&', $artist))));
            $artist = array_shift($parts) ?? '';
            $featuring = implode(', ', $parts);
        }

        $releaseValue = ($page['rows']['releaseDate'] ?? '')
            ?: $this->firstValue($music, ['released', 'releaseDate', 'release_date', 'uploaded', 'released_at']);
        $release = $this->formatReleaseDate($releaseValue);
        $duration = $this->durationSeconds($page['music']['duration'] ?? 0);
        $stream = $page['streamingUrl'] ?: $this->firstValue(
            $music,
            ['streaming_url', 'streamingUrl', 'signedUrl', 'signed_url', 'url']
        );
        if ($stream !== '' && !$this->isAudioUrl((string) $stream)) {
            $stream = '';
        }

        return [
            'songId' => $this->songId($music, (string) $stream, (string) ($page['html'] ?? ''), $url),
            'title' => $title,
            'artist' => trim(str_replace('Listen on Audiomack', '', $artist)),
            'featuringArtists' => $featuring,
            'duration' => $this->formatDuration($duration),
            'genre' => ($page['rows']['genre'] ?? '') ?: $this->firstValue($music, ['genre', 'genres']),
            'producer' => $this->firstValue($music, ['producer', 'producers', 'credits']) ?: ($page['rows']['producer'] ?? ''),
            'releaseDate' => $release[0],
            'year' => $release[1],
            'trackNumber' => $number,
            'streamingUrl' => $stream,
            'trackImageUrl' => $this->firstValue($music, ['image', 'cover', 'imageUrl', 'image_url', 'coverImage'])
                ?: ($page['ogImage'] ?? ''),
        ];
    }

    private function albumDuration(array $page, array $music, int $trackCount): int
    {
        $duration = $this->durationSeconds($this->firstValue(
            $music,
            ['duration', 'durationSeconds', 'duration_seconds', 'total_duration', 'totalDuration']
        ));
        if ($duration > 0) {
            return $duration;
        }

        if (isset($music['tracks']) && is_array($music['tracks'])) {
            foreach ($music['tracks'] as $track) {
                if (is_array($track)) {
                    $duration += $this->durationSeconds(
                        $this->firstValue($track, ['duration', 'durationSeconds', 'duration_seconds'])
                    );
                }
            }
            if ($duration > 0) {
                return $duration;
            }
        }

        $matches = [];
        preg_match_all('/"duration"\s*:\s*"?(\d+)"?/', $page['html'] ?? '', $matches);
        $valid = array_values(array_filter(array_map('intval', $matches[1] ?? []), static fn (int $value): bool => $value >= 30 && $value <= 1800));
        if ($valid !== []) {
            return array_sum($trackCount > 0 && count($valid) >= $trackCount ? array_slice($valid, 0, $trackCount) : $valid);
        }

        $domDurations = $page['domDurations'] ?? [];
        if ($domDurations !== []) {
            return array_sum(array_map(
                fn (string $value): int => $this->durationSeconds($value),
                $trackCount > 0 ? array_slice($domDurations, 0, $trackCount) : $domDurations
            ));
        }

        return 0;
    }

    private function songId(array $music, string $stream, string $html, string $url): string
    {
        $value = $this->firstValue($music, ['songId', 'song_id', 'trackId', 'track_id', 'id', 'id_music']);
        if ((string) $value !== '' && ctype_digit((string) $value)) {
            return (string) $value;
        }
        if (preg_match('/_audtrk__(\d+)/', $stream, $match) || preg_match('/\/(\d+)(?:\.[a-zA-Z0-9]+|\?)/', $stream, $match)) {
            return $match[1];
        }
        if (preg_match('/"(?:songId|song_id|trackId|track_id|id)"\s*:\s*"?(\d+)/i', $html, $match)) {
            return $match[1];
        }
        if (preg_match('/-(\d+)(?:[\/?#]|$)/', $url, $match)) {
            return $match[1];
        }
        return '';
    }

    private function firstValue(array $object, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = $object[$key] ?? null;
            if ($value !== null && $value !== '' && $value !== []) {
                return $value;
            }
        }
        return '';
    }

    private function cleanText(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $cleaned = preg_replace('/\s+/u', ' ', str_replace("\u{00a0}", ' ', (string) $value));
        return trim($cleaned ?? (string) $value);
    }

    private function cleanOgTitle(string $title): string
    {
        return $this->cleanText(preg_replace('/\s*:\s*Listen on Audiomack.*$/i', '', $title) ?? $title);
    }

    private function durationSeconds(mixed $value): int
    {
        if (is_bool($value) || $value === null || $value === '') {
            return 0;
        }
        if (is_numeric($value)) {
            return max(0, (int) $value);
        }
        $value = $this->cleanText($value);
        if (preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/i', $value, $match)
            && (isset($match[1]) || isset($match[2]) || isset($match[3]))) {
            return ((int) ($match[1] ?? 0) * 3600) + ((int) ($match[2] ?? 0) * 60) + (int) ($match[3] ?? 0);
        }
        if (preg_match('/^(\d+):(\d{1,2}):(\d{1,2})$/', $value, $match)) {
            return (int) $match[1] * 3600 + (int) $match[2] * 60 + (int) $match[3];
        }
        if (preg_match('/^(\d+):(\d{1,2})$/', $value, $match)) {
            return (int) $match[1] * 60 + (int) $match[2];
        }
        return 0;
    }

    private function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remaining = $seconds % 60;
        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $remaining)
            : sprintf('%d:%02d', $minutes, $remaining);
    }

    private function formatReleaseDate(mixed $value): array
    {
        $value = $this->cleanText($value);
        $value = preg_replace('/[®™©‡Ⓡ]/u', '', $value) ?? $value;
        $value = $this->cleanText($value);
        if ($value === '') {
            return ['', ''];
        }

        $date = $this->parseDate($value);
        if ($date !== null) {
            return [$date->format('F') . ' ' . $this->ordinal((int) $date->format('j')), $date->format('Y')];
        }
        if (preg_match('/\b((?:19|20)\d{2})\b/', $value, $match)) {
            return [$value, $match[1]];
        }
        return [$value, ''];
    }

    private function songReleaseDate(mixed $value): array
    {
        $value = $this->cleanText($value);
        $date = $this->parseDate($value);
        if ($date !== null) {
            return [$date->format('Y-m-d'), $date->format('Y')];
        }
        $year = date('Y');
        return [$year . '-01-01', $year];
    }

    private function parseDate(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value) && (int) $value > 100_000_000) {
            return (new DateTimeImmutable('@' . (int) $value))->setTimezone(new DateTimeZone('UTC'));
        }
        foreach (['!F j, Y', '!M j, Y', '!Y-m-d', '!Y/m/d'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false) {
                return $date;
            }
        }
        try {
            return new DateTimeImmutable(str_replace('Z', '+00:00', $value));
        } catch (Throwable) {
            return null;
        }
    }

    private function ordinal(int $day): string
    {
        if ($day % 100 >= 10 && $day % 100 <= 20) {
            return $day . 'th';
        }
        return $day . match ($day % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }

    private function albumSlug(string $url): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $parts = explode('/', $path);
        return count($parts) >= 3 ? (string) end($parts) : '';
    }

    private function resolveUrl(string $base, string $relative): string
    {
        if (preg_match('/^https?:\/\//i', $relative)) {
            return $relative;
        }
        $parts = parse_url($base);
        if (!isset($parts['scheme'], $parts['host'])) {
            return $relative;
        }
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        return str_starts_with($relative, '/')
            ? $origin . $relative
            : $origin . rtrim(dirname($parts['path'] ?? '/'), '/') . '/' . $relative;
    }

    private function isAudioUrl(string $url): bool
    {
        foreach (self::AUDIO_MARKERS as $marker) {
            if (str_contains(strtolower($url), $marker)) {
                return true;
            }
        }
        return false;
    }
}