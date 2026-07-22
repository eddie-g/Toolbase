<?php

namespace App\Services;

use App\Exceptions\AdobePdfServicesException;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class AdobePdfServices
{
    private ?string $accessToken = null;

    public function configured(): bool
    {
        return filled(config('services.adobe_pdf_services.client_id'))
            && filled(config('services.adobe_pdf_services.client_secret'));
    }

    public function testConnection(): array
    {
        $this->getAccessToken();

        return [
            'connected' => true,
            'region' => strtoupper((string) config('services.adobe_pdf_services.region', 'US')),
        ];
    }

    public function export(string $inputPath, string $outputPath, string $targetFormat): array
    {
        $targetFormat = strtolower($targetFormat);
        if (! in_array($targetFormat, ['docx', 'xlsx'], true)) {
            throw new AdobePdfServicesException("Adobe export format [{$targetFormat}] is not supported.");
        }

        if (! is_file($inputPath) || ! is_readable($inputPath)) {
            throw new AdobePdfServicesException('The PDF input file is missing or unreadable.');
        }

        $token = $this->getAccessToken();
        $asset = $this->createAsset($token);
        $this->uploadAsset($asset['uploadUri'], $inputPath);
        $location = $this->submitExport($token, $asset['assetID'], $targetFormat);
        $job = $this->waitForExport($token, $location);
        $downloadUri = $this->resolveDownloadUri($token, $job);
        $this->downloadAsset($downloadUri, $outputPath);

        return [
            'success' => true,
            'engine' => 'adobe_pdf_services',
            'provider' => 'adobe',
            'target_format' => $targetFormat,
            'file_size' => filesize($outputPath),
            'job_id' => $this->jobIdFromLocation($location),
        ];
    }

    private function getAccessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        if (! $this->configured()) {
            throw new AdobePdfServicesException('Adobe PDF Services credentials are not configured.');
        }

        $response = Http::asForm()
            ->acceptJson()
            ->timeout($this->requestTimeout())
            ->post($this->tokenUrl(), [
                'client_id' => config('services.adobe_pdf_services.client_id'),
                'client_secret' => config('services.adobe_pdf_services.client_secret'),
                'grant_type' => 'client_credentials',
                'scope' => config('services.adobe_pdf_services.scopes', 'openid,AdobeID,DCAPI'),
            ]);

        $this->ensureSuccessful($response, 'authenticate with Adobe PDF Services');
        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new AdobePdfServicesException('Adobe PDF Services did not return an access token.');
        }

        return $this->accessToken = $token;
    }

    private function createAsset(string $token): array
    {
        $response = $this->adobeRequest($token)
            ->post($this->baseUrl().'/assets', ['mediaType' => 'application/pdf']);

        $this->ensureSuccessful($response, 'create an Adobe upload asset');
        $uploadUri = $response->json('uploadUri');
        $assetId = $response->json('assetID');

        if (! is_string($uploadUri) || $uploadUri === '' || ! is_string($assetId) || $assetId === '') {
            throw new AdobePdfServicesException('Adobe PDF Services returned an invalid upload asset.');
        }

        return ['uploadUri' => $uploadUri, 'assetID' => $assetId];
    }

    private function uploadAsset(string $uploadUri, string $inputPath): void
    {
        $handle = fopen($inputPath, 'rb');
        if ($handle === false) {
            throw new AdobePdfServicesException('Unable to open the PDF for Adobe upload.');
        }

        try {
            $response = Http::timeout($this->requestTimeout())
                ->withBody(Utils::streamFor($handle), 'application/pdf')
                ->put($uploadUri);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $this->ensureSuccessful($response, 'upload the PDF to Adobe');
    }

    private function submitExport(string $token, string $assetId, string $targetFormat): string
    {
        $response = $this->adobeRequest($token)
            ->post($this->baseUrl().'/operation/exportpdf', [
                'assetID' => $assetId,
                'targetFormat' => $targetFormat,
            ]);

        $this->ensureSuccessful($response, 'start the Adobe export');
        $location = $response->header('Location');
        if (! is_string($location) || trim($location) === '') {
            throw new AdobePdfServicesException('Adobe PDF Services did not return a job status URL.');
        }

        return $this->absoluteAdobeUrl($location);
    }

    private function waitForExport(string $token, string $location): array
    {
        $deadline = microtime(true) + max(1, (int) config('services.adobe_pdf_services.poll_timeout', 180));

        do {
            $response = $this->adobeRequest($token)->get($location);
            $this->ensureSuccessful($response, 'check the Adobe export status');
            $job = $response->json();
            $status = strtolower(trim((string) ($job['status'] ?? '')));

            if (in_array($status, ['done', 'succeeded', 'success'], true)) {
                return is_array($job) ? $job : [];
            }

            if (in_array($status, ['failed', 'error'], true)) {
                $message = data_get($job, 'error.message')
                    ?? data_get($job, 'error.code')
                    ?? 'Adobe could not convert this PDF.';
                throw new AdobePdfServicesException('Adobe PDF Services export failed: '.$message);
            }

            if (! in_array($status, ['in progress', 'in_progress', 'inprogress', 'pending', 'running'], true)) {
                throw new AdobePdfServicesException('Adobe PDF Services returned an unknown export status.');
            }

            $interval = max(0, (int) config('services.adobe_pdf_services.poll_interval_ms', 1000));
            if ($interval > 0) {
                usleep($interval * 1000);
            }
        } while (microtime(true) < $deadline);

        throw new AdobePdfServicesException('Adobe PDF Services export timed out.');
    }

    private function resolveDownloadUri(string $token, array $job): string
    {
        $downloadUri = data_get($job, 'asset.downloadUri')
            ?? data_get($job, 'asset.downloadURI')
            ?? data_get($job, 'result.asset.downloadUri')
            ?? data_get($job, 'result.asset.downloadURI')
            ?? ($job['downloadUri'] ?? null)
            ?? ($job['downloadURI'] ?? null);

        if (is_string($downloadUri) && $downloadUri !== '') {
            return $downloadUri;
        }

        $assetId = data_get($job, 'asset.assetID')
            ?? data_get($job, 'result.asset.assetID')
            ?? ($job['assetID'] ?? null);

        if (is_string($assetId) && $assetId !== '') {
            $response = $this->adobeRequest($token)
                ->get($this->baseUrl().'/assets/'.rawurlencode($assetId));
            $this->ensureSuccessful($response, 'get the Adobe download URL');
            $downloadUri = $response->json('downloadUri');
        }

        if (! is_string($downloadUri) || $downloadUri === '') {
            throw new AdobePdfServicesException('Adobe PDF Services did not return a download URL.');
        }

        return $downloadUri;
    }

    private function downloadAsset(string $downloadUri, string $outputPath): void
    {
        $directory = dirname($outputPath);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new AdobePdfServicesException('Unable to create the conversion output directory.');
        }

        $response = Http::timeout($this->requestTimeout())
            ->withOptions(['sink' => $outputPath])
            ->get($downloadUri);

        if (! $response->successful() || ! is_file($outputPath) || filesize($outputPath) === 0) {
            @unlink($outputPath);
            $this->ensureSuccessful($response, 'download the converted Adobe document');
            throw new AdobePdfServicesException('Adobe returned an empty converted document.');
        }
    }

    private function adobeRequest(string $token): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->withToken($token)
            ->withHeaders(['x-api-key' => (string) config('services.adobe_pdf_services.client_id')])
            ->timeout($this->requestTimeout());
    }

    private function ensureSuccessful(Response $response, string $operation): void
    {
        if ($response->successful()) {
            return;
        }

        $errorCode = $response->json('error.code');
        $message = $response->json('error.message')
            ?? $response->json('message')
            ?? $errorCode
            ?? ('HTTP '.$response->status());

        if (is_string($errorCode) && $errorCode !== '' && $errorCode !== $message) {
            $message .= " [{$errorCode}]";
        }

        throw new AdobePdfServicesException("Unable to {$operation}: {$message} (HTTP {$response->status()})");
    }

    private function baseUrl(): string
    {
        $configured = trim((string) config('services.adobe_pdf_services.base_url'));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $region = strtoupper(trim((string) config('services.adobe_pdf_services.region', 'US')));

        return in_array($region, ['EU', 'EUROPE', 'EW1'], true)
            ? 'https://pdf-services-ew1.adobe.io'
            : 'https://pdf-services.adobe.io';
    }

    private function tokenUrl(): string
    {
        return (string) config(
            'services.adobe_pdf_services.token_url',
            'https://ims-na1.adobelogin.com/ims/token/v3'
        );
    }

    private function absoluteAdobeUrl(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return $this->baseUrl().'/'.ltrim($url, '/');
    }

    private function requestTimeout(): int
    {
        return max(1, (int) config('services.adobe_pdf_services.request_timeout', 120));
    }

    private function jobIdFromLocation(string $location): ?string
    {
        $path = parse_url($location, PHP_URL_PATH);
        if (! is_string($path)) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $path)));

        if (strtolower((string) end($segments)) === 'status') {
            array_pop($segments);
        }

        return $segments === [] ? null : end($segments);
    }
}
