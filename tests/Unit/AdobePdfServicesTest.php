<?php

namespace Tests\Unit;

use App\Exceptions\AdobePdfServicesException;
use App\Services\AdobePdfServices;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdobePdfServicesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.adobe_pdf_services', [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'region' => 'US',
            'base_url' => 'https://pdf-services.test',
            'token_url' => 'https://pdf-services.test/token',
            'scopes' => 'openid,AdobeID,DCAPI',
            'request_timeout' => 10,
            'poll_timeout' => 2,
            'poll_interval_ms' => 0,
        ]);
    }

    public function test_it_exports_a_pdf_to_docx_through_the_adobe_rest_flow(): void
    {
        $polls = 0;
        Http::fake(function (Request $request) use (&$polls) {
            return match (true) {
                $request->url() === 'https://pdf-services.test/token' => Http::response([
                    'access_token' => 'access-token',
                    'expires_in' => 86399,
                ]),
                $request->url() === 'https://pdf-services.test/assets' => Http::response([
                    'assetID' => 'urn:aaid:test-input',
                    'uploadUri' => 'https://storage.test/upload',
                ]),
                $request->url() === 'https://storage.test/upload' => Http::response('', 200),
                $request->url() === 'https://pdf-services.test/operation/exportpdf' => Http::response(
                    [],
                    201,
                    ['Location' => '/operation/exportpdf/job-123/status']
                ),
                $request->url() === 'https://pdf-services.test/operation/exportpdf/job-123/status' => ++$polls === 1
                        ? Http::response(['status' => 'in progress'])
                        : Http::response([
                            'status' => 'done',
                            'asset' => ['downloadUri' => 'https://storage.test/result.docx'],
                        ]),
                $request->url() === 'https://storage.test/result.docx' => Http::response('docx-bytes'),
                default => Http::response(['error' => ['message' => 'Unexpected request']], 500),
            };
        });

        $inputPath = tempnam(sys_get_temp_dir(), 'adobe-input-');
        $outputPath = tempnam(sys_get_temp_dir(), 'adobe-output-');
        @unlink($outputPath);
        file_put_contents($inputPath, '%PDF-1.7 test');

        try {
            $result = app(AdobePdfServices::class)->export($inputPath, $outputPath, 'docx');

            $this->assertSame('adobe_pdf_services', $result['engine']);
            $this->assertSame('docx', $result['target_format']);
            $this->assertSame('job-123', $result['job_id']);
            $this->assertSame('docx-bytes', file_get_contents($outputPath));

            Http::assertSent(function (Request $request) {
                return $request->url() === 'https://pdf-services.test/operation/exportpdf'
                    && $request['assetID'] === 'urn:aaid:test-input'
                    && $request['targetFormat'] === 'docx'
                    && $request->hasHeader('x-api-key', 'test-client-id')
                    && $request->hasHeader('Authorization', 'Bearer access-token');
            });

            Http::assertSent(function (Request $request) {
                return $request->url() === 'https://pdf-services.test/token'
                    && $request['grant_type'] === 'client_credentials'
                    && $request['scope'] === 'openid,AdobeID,DCAPI';
            });
        } finally {
            @unlink($inputPath);
            @unlink($outputPath);
        }
    }

    public function test_it_can_resolve_a_completed_job_asset_id_before_downloading_xlsx(): void
    {
        Http::fake([
            'https://pdf-services.test/token' => Http::response(['access_token' => 'access-token']),
            'https://pdf-services.test/assets' => Http::sequence()
                ->push(['assetID' => 'input-id', 'uploadUri' => 'https://storage.test/upload'])
                ->push(['downloadUri' => 'https://storage.test/result.xlsx']),
            'https://storage.test/upload' => Http::response('', 200),
            'https://pdf-services.test/operation/exportpdf' => Http::response(
                [],
                201,
                ['Location' => 'https://pdf-services.test/operation/exportpdf/job-xlsx/status']
            ),
            'https://pdf-services.test/operation/exportpdf/job-xlsx/status' => Http::response([
                'status' => 'done',
                'asset' => ['assetID' => 'result-id'],
            ]),
            'https://pdf-services.test/assets/result-id' => Http::response([
                'downloadUri' => 'https://storage.test/result.xlsx',
            ]),
            'https://storage.test/result.xlsx' => Http::response('xlsx-bytes'),
        ]);

        $inputPath = tempnam(sys_get_temp_dir(), 'adobe-input-');
        $outputPath = tempnam(sys_get_temp_dir(), 'adobe-output-');
        @unlink($outputPath);
        file_put_contents($inputPath, '%PDF-1.7 test');

        try {
            $result = app(AdobePdfServices::class)->export($inputPath, $outputPath, 'xlsx');

            $this->assertSame('xlsx', $result['target_format']);
            $this->assertSame('xlsx-bytes', file_get_contents($outputPath));
        } finally {
            @unlink($inputPath);
            @unlink($outputPath);
        }
    }

    public function test_it_rejects_an_unsupported_export_format_without_calling_adobe(): void
    {
        Http::fake();

        $this->expectException(AdobePdfServicesException::class);
        $this->expectExceptionMessage('not supported');

        app(AdobePdfServices::class)->export(__FILE__, sys_get_temp_dir().'/unused.pptx', 'pptx');
    }
}
