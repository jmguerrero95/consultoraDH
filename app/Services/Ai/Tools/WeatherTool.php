<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Domain\Ai\AiToolRejected;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Current weather and a short forecast from Open-Meteo.
 *
 * ## Why a dedicated tool exists at all
 *
 * The general web research mechanism is the primary way the assistant answers
 * about the world, and it is what handles news, regulations, prices and
 * documentation. Weather is the case where that mechanism is weakest: a search
 * engine returns pages *about* the weather, and the numbers are rendered by
 * JavaScript or buried behind a consent wall, so "temperatura y humedad" came
 * back as a list of sites to visit. Open-Meteo publishes the same observation as
 * JSON, free, without a key, with a documented licence, so this tool reads it
 * directly instead of scraping a page that does not want to be scraped.
 *
 * It specializes one subject, not the mechanism: the fallback when it cannot
 * answer is the ordinary search, and every other "what is true today" question
 * still goes through `web_search` and `web_fetch`.
 *
 * ## No keys, no payment, no invented numbers
 *
 * Two fixed Open-Meteo endpoints are called — geocoding and forecast — and the
 * host is never taken from an argument, so there is nothing here to point at an
 * internal service. Values come back with the provider's own units and the
 * timezone of the place, and a place that cannot be geocoded is reported as not
 * found rather than approximated.
 */
final class WeatherTool extends AbstractAiTool
{
    /**
     * WMO weather codes, in the words a person uses.
     *
     * @var array<int, string>
     */
    private const CONDITIONS = [
        0 => 'despejado',
        1 => 'mayormente despejado',
        2 => 'parcialmente nublado',
        3 => 'nublado',
        45 => 'niebla',
        48 => 'niebla con escarcha',
        51 => 'llovizna ligera',
        53 => 'llovizna moderada',
        55 => 'llovizna intensa',
        56 => 'llovizna helada ligera',
        57 => 'llovizna helada intensa',
        61 => 'lluvia ligera',
        63 => 'lluvia moderada',
        65 => 'lluvia intensa',
        66 => 'lluvia helada ligera',
        67 => 'lluvia helada intensa',
        71 => 'nieve ligera',
        73 => 'nieve moderada',
        75 => 'nieve intensa',
        77 => 'granos de nieve',
        80 => 'chubascos ligeros',
        81 => 'chubascos moderados',
        82 => 'chubascos violentos',
        85 => 'chubascos de nieve ligeros',
        86 => 'chubascos de nieve intensos',
        95 => 'tormenta',
        96 => 'tormenta con granizo ligero',
        99 => 'tormenta con granizo intenso',
    ];

    public function name(): string
    {
        return 'weather_current';
    }

    public function description(): string
    {
        return 'Temperatura, humedad relativa y pronóstico reales de una ubicación, ahora y para los próximos días. '
            .'Usa la API pública y gratuita de Open-Meteo (geocodificación + pronóstico). Devuelve valores con unidades, '
            .'hora de observación y zona horaria. Úsala cuando pregunten por el clima, la temperatura, la humedad o el '
            .'pronóstico de un lugar.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'location' => [
                    'type' => 'string',
                    'description' => 'Municipio, ciudad o región, por ejemplo "Girón, Santander, Colombia".',
                ],
                'days' => [
                    'type' => 'integer',
                    'description' => 'Días de pronóstico (1 a 3, incluido el día de hoy).',
                    'default' => 2,
                ],
            ],
            'required' => ['location'],
        ];
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function validate(array $arguments): array
    {
        $location = $this->requireString($arguments, 'location', 160);
        $days = $arguments['days'] ?? 2;

        // Models routinely send an integer as a numeric string. Accepting it here
        // turns a needless "days must be an integer" round-trip — which a weak
        // model then reports to the person instead of retrying — into the
        // successful call the argument already meant.
        if (is_string($days) && preg_match('/^\s*\d+\s*$/', $days) === 1) {
            $days = (int) trim($days);
        } elseif (is_float($days) && $days === floor($days)) {
            $days = (int) $days;
        }

        if (! is_int($days) || $days < 1 || $days > 3) {
            throw new AiToolRejected("'days' debe ser un entero entre 1 y 3.");
        }

        return ['location' => $location, 'days' => $days];
    }

    public function describe(array $arguments): string
    {
        return sprintf('Consultar clima actual de %s.', $arguments['location']);
    }

    public function execute(array $arguments, User $user): array
    {
        $this->authorize($user, 'web_search');

        if (! config('ai.weather.enabled', true)) {
            throw new AiToolRejected('La consulta meteorológica no está habilitada en esta instalación.');
        }

        $location = (string) $arguments['location'];
        $days = (int) $arguments['days'];

        $place = $this->geocode($location);

        if ($place === null) {
            throw new AiToolRejected(sprintf('No se encontró la ubicación «%s». Indica municipio y país.', $location));
        }

        $forecast = $this->forecast($place, $days);

        return [
            'location' => [
                'name' => $place['name'],
                'admin1' => $place['admin1'],
                'country' => $place['country'],
                'latitude' => $place['latitude'],
                'longitude' => $place['longitude'],
                'timezone' => $place['timezone'],
            ],
            'observed_at' => $forecast['current_time'],
            'timezone' => $forecast['timezone'],
            'units' => [
                'temperature' => '°C',
                'relative_humidity' => '%',
                'precipitation' => 'mm',
            ],
            'current' => $forecast['current'],
            'daily' => $forecast['daily'],
            'source' => 'Open-Meteo',
            'source_url' => 'https://open-meteo.com/',
            'note' => 'Valores modelados por Open-Meteo a partir de observaciones; no son una medición local puntual.',
        ];
    }

    /**
     * Geocode a place name, trying the phrase and then shorter candidates.
     *
     * "Girón Santander" is not a name the geocoder knows; "Girón" is. Rather than
     * guess which words are the place, the candidates are tried in order of
     * specificity and the first that resolves is used. A Colombian result is
     * preferred when the text itself mentions Colombia or one of its departments,
     * which is the difference between Girón (Santander) and Giron (France).
     *
     * @return array{name: string, admin1: string, country: string, latitude: float, longitude: float, timezone: string}|null
     */
    private function geocode(string $location): ?array
    {
        $candidates = $this->locationCandidates($location);

        if ($candidates === []) {
            return null;
        }

        $preferColombia = (bool) preg_match(
            '/\b(colombia|santander|antioquia|cundinamarca|valle|atlantico|bolivar|norte de santander|boyaca|tolima|huila|narino|cauca|caldas|risaralda|quindio|magdalena|cesar|cordoba|sucre|bolivar)\b/i',
            $location,
        );

        foreach ($candidates as $candidate) {
            $results = $this->searchPlaces($candidate);

            if ($results === []) {
                continue;
            }

            $chosen = $results[0];

            if ($preferColombia) {
                foreach ($results as $result) {
                    if (($result['country_code'] ?? '') === 'CO') {
                        $chosen = $result;

                        break;
                    }
                }
            }

            return [
                'name' => (string) ($chosen['name'] ?? $candidate),
                'admin1' => (string) ($chosen['admin1'] ?? ''),
                'country' => (string) ($chosen['country'] ?? ''),
                'latitude' => (float) $chosen['latitude'],
                'longitude' => (float) $chosen['longitude'],
                'timezone' => (string) ($chosen['timezone'] ?? 'auto'),
            ];
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchPlaces(string $name): array
    {
        $url = (string) config('ai.weather.geocoding_url');
        $timeout = (int) config('ai.weather.timeout_seconds', 10);

        try {
            $response = Http::timeout($timeout)
                ->withHeaders(['User-Agent' => 'Consultora-DH-Assistant/1.0', 'Accept' => 'application/json'])
                ->withQueryParameters([
                    'name' => $name,
                    'count' => 5,
                    'language' => 'es',
                    'format' => 'json',
                ])
                ->get($url);
        } catch (\Throwable $e) {
            throw new AiToolRejected('No fue posible consultar el servicio meteorológico: '.$e->getMessage());
        }

        if ($response->failed()) {
            throw new AiToolRejected('El servicio meteorológico respondió '.$response->status().'.');
        }

        $data = $response->json();

        if (! is_array($data)) {
            return [];
        }

        return array_values(array_filter(
            $data['results'] ?? [],
            static fn ($row): bool => is_array($row) && isset($row['latitude'], $row['longitude']),
        ));
    }

    /**
     * The name phrased as-is, then progressively less specific: the words up to
     * each comma, then the first word, then the longest word.
     *
     * @return list<string>
     */
    private function locationCandidates(string $location): array
    {
        $candidates = [];
        $parts = array_values(array_filter(array_map('trim', explode(',', $location)), static fn (string $p): bool => $p !== ''));

        // The comma-separated prefixes, most specific first.
        $accumulated = '';
        foreach ($parts as $part) {
            $accumulated = $accumulated === '' ? $part : $accumulated.' '.$part;
            $candidates[] = $accumulated;
        }

        $words = preg_split('/\s+/', trim($location)) ?: [];

        if (count($words) > 1) {
            $candidates[] = $words[0];
            $candidates[] = $words[count($words) - 1];
        }

        $candidates[] = trim($location);

        // Longest word first: "Girón" before "de".
        usort($words, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach (array_slice($words, 0, 3) as $word) {
            $candidates[] = $word;
        }

        return array_values(array_unique(array_filter(
            $candidates,
            static fn (string $c): bool => mb_strlen(trim($c)) >= 2,
        )));
    }

    /**
     * @param  array{latitude: float, longitude: float, timezone: string}  $place
     * @return array{current_time: string, timezone: string, current: array<string, mixed>, daily: list<array<string, mixed>>}
     */
    private function forecast(array $place, int $days): array
    {
        $url = (string) config('ai.weather.forecast_url');
        $timeout = (int) config('ai.weather.timeout_seconds', 10);

        $timezone = $place['timezone'] !== '' ? $place['timezone'] : 'America/Bogota';

        try {
            $response = Http::timeout($timeout)
                ->withHeaders(['User-Agent' => 'Consultora-DH-Assistant/1.0', 'Accept' => 'application/json'])
                ->withQueryParameters([
                    'latitude' => $place['latitude'],
                    'longitude' => $place['longitude'],
                    'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,precipitation,weather_code',
                    'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max',
                    'timezone' => $timezone,
                    'forecast_days' => max(1, min(3, $days)),
                ])
                ->get($url);
        } catch (\Throwable $e) {
            throw new AiToolRejected('No fue posible obtener el pronóstico: '.$e->getMessage());
        }

        if ($response->failed()) {
            throw new AiToolRejected('El servicio meteorológico respondió '.$response->status().'.');
        }

        $data = $response->json();

        if (! is_array($data) || ! isset($data['current'])) {
            throw new AiToolRejected('El servicio meteorológico devolvió una respuesta inesperada.');
        }

        $current = $data['current'];

        return [
            'current_time' => (string) ($current['time'] ?? ''),
            'timezone' => (string) ($data['timezone'] ?? $timezone),
            'current' => [
                'temperature_c' => $current['temperature_2m'] ?? null,
                'relative_humidity_pct' => $current['relative_humidity_2m'] ?? null,
                'apparent_temperature_c' => $current['apparent_temperature'] ?? null,
                'precipitation_mm' => $current['precipitation'] ?? null,
                'weather_code' => $current['weather_code'] ?? null,
                'condition' => $this->condition($current['weather_code'] ?? null),
            ],
            'daily' => $this->daily($data['daily'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $daily
     * @return list<array<string, mixed>>
     */
    private function daily(array $daily): array
    {
        $dates = $daily['time'] ?? [];

        if (! is_array($dates)) {
            return [];
        }

        $rows = [];

        foreach ($dates as $index => $date) {
            $rows[] = [
                'date' => (string) $date,
                'min_c' => $daily['temperature_2m_min'][$index] ?? null,
                'max_c' => $daily['temperature_2m_max'][$index] ?? null,
                'precipitation_probability_pct' => $daily['precipitation_probability_max'][$index] ?? null,
                'weather_code' => $daily['weather_code'][$index] ?? null,
                'condition' => $this->condition($daily['weather_code'][$index] ?? null),
            ];
        }

        return $rows;
    }

    private function condition(mixed $code): string
    {
        if ($code === null || ! is_numeric($code)) {
            return 'sin dato';
        }

        return self::CONDITIONS[(int) $code] ?? 'condición '.$code;
    }
}
