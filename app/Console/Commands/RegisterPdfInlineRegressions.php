<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\PdfState;
use App\Models\PdfUploadTest;
use App\Support\PdfInlineRegressionCases;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RegisterPdfInlineRegressions extends Command
{
    protected $signature = 'pdf-tests:register-inline-regressions {--dry-run}';

    protected $description = 'Register retained copies and admin cases for the reported PDF inline regressions';

    public function handle(): int
    {
        foreach (collect(PdfInlineRegressionCases::all())->groupBy('document_id') as $sourceId => $cases) {
            $source = Document::findOrFail($sourceId);
            if (! $source->admin_id) {
                $this->error('Document '.$sourceId.' has no admin owner.');

                return self::FAILURE;
            }
            $name = 'Inline regressions - '.$source->original_name;
            $fixture = PdfUploadTest::where('admin_id', $source->admin_id)->where('original_name', $name)->first();
            if ($this->option('dry-run')) {
                $this->line(($fixture ? 'Reuse' : 'Create').' '.$name.' for admin '.$source->admin_id.': '.$cases->count().' cases');
                continue;
            }
            if (! $fixture) {
                $contents = Storage::disk('local')->get($source->original_backup_path ?: $source->path);
                $uuid = (string) Str::uuid();
                $directory = 'pdf-upload-tests/'.$uuid;
                if (! Storage::disk('local')->put($directory.'/original.pdf', $contents)
                    || ! Storage::disk('local')->put($directory.'/current.pdf', $contents)) {
                    throw new \RuntimeException('Could not retain the original PDF for '.$name);
                }
                $fixture = DB::transaction(function () use ($source, $name, $contents, $uuid, $directory) {
                    $document = $source->replicate();
                    $document->path = $directory.'/current.pdf';
                    $document->original_backup_path = $directory.'/original.pdf';
                    $document->original_name = $name;
                    $document->mode = 'regression';
                    $document->save();
                    foreach (PdfState::where('document_id', $source->id)->get() as $state) {
                        $copy = $state->replicate();
                        $copy->document_id = $document->id;
                        $copy->annotation_data = json_decode(str_replace(
                            'pdfjs_'.$source->id.'_',
                            'pdfjs_'.$document->id.'_',
                            json_encode($state->annotation_data, JSON_THROW_ON_ERROR)
                        ), true, 512, JSON_THROW_ON_ERROR);
                        $copy->save();
                    }
                    foreach (DB::table('pdf_extractions_fitz')->where('document_id', $source->id)->get() as $extraction) {
                        $copy = (array) $extraction;
                        unset($copy['id']);
                        $copy['document_id'] = $document->id;
                        DB::table('pdf_extractions_fitz')->insert($copy);
                    }

                    return PdfUploadTest::create([
                        'uuid' => $uuid,
                        'admin_id' => $source->admin_id,
                        'document_id' => $document->id,
                        'original_name' => $name,
                        'mime_type' => 'application/pdf',
                        'size_bytes' => strlen($contents),
                        'sha256' => hash('sha256', $contents),
                        'pdf_base64' => base64_encode($contents),
                        'paragraph_grouping_enabled' => true,
                    ]);
                });
            }
            foreach ($cases as $case) {
                $annotationId = preg_replace('/^pdfjs_\d+_/', 'pdfjs_'.$fixture->document_id.'_', $case['annotation_id']);
                $state = PdfState::where('document_id', $fixture->document_id)
                    ->where('page_number', $case['page_index'])
                    ->get()->first(fn ($state) => ($state->annotation_data['id'] ?? null) === $annotationId);
                $testCase = $fixture->cases()->firstOrCreate(['annotation_id' => $annotationId], [
                    'runtime_annotation_id' => $annotationId,
                    'page_index' => $case['page_index'],
                    'target_text' => mb_substr((string) ($state?->annotation_data['text'] ?? ''), 0, 2000),
                    'test_comment' => '[inline-regression:'.$case['key'].'] '.$case['instruction'],
                    'test_saved_at' => now(),
                ]);
                $this->line($case['key'].' fixture='.$fixture->id.' case='.$testCase->id.' test_id='.$testCase->test_id);
            }
        }

        return self::SUCCESS;
    }
}