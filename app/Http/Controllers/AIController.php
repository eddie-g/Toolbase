<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AiRequest;
use App\Models\AiResponse;
use App\Models\AiSection;
use App\Models\AiImage;
use App\Models\AiPriceLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Services\DeveloperChatClient;
use App\Services\AICostCalculator;

class AIController extends Controller
{
    public function chat(Request $request)
    {
        try {
            $validated = $request->validate([
                'prompt' => 'required|string',
                'template' => 'nullable|string',
                'document_id' => 'nullable|string',
                'sections' => 'nullable|array',
                'sections.*.type' => 'nullable|string',
                'sections.*.name' => 'nullable|string',
                'sections.*.height' => 'nullable|numeric',
                'sections.*.width' => 'nullable|numeric',
                'sections.*.x' => 'nullable|numeric',
                'sections.*.y' => 'nullable|numeric',
                'sections.*.order' => 'nullable|integer',
                'sections.*.page' => 'nullable|integer',
                'sections.*.dimensions' => 'nullable|array',
                'sections.*.dimensions.pageWidth' => 'nullable|numeric',
                'sections.*.dimensions.pageHeight' => 'nullable|numeric',
                'sections.*.dimensions.sectionWidthPx' => 'nullable|numeric',
                'sections.*.dimensions.sectionHeightPx' => 'nullable|numeric',
                'sections.*.dimensions.sectionXPx' => 'nullable|numeric',
                'sections.*.dimensions.sectionYPx' => 'nullable|numeric',
                'model' => 'nullable|string',
                'prompt_settings' => 'nullable|array',
                'prompt_settings.style' => 'nullable|string',
                'prompt_settings.quality' => 'nullable|string',
                'prompt_settings.additional' => 'nullable|string',
                'confirmed' => 'nullable|boolean',
            ]);

            // Store prompt settings in session if provided
            if (isset($validated['prompt_settings'])) {
                session([
                    'prompt_style' => $validated['prompt_settings']['style'] ?? 'modern and professional',
                    'prompt_quality' => $validated['prompt_settings']['quality'] ?? 'high-quality, photorealistic',
                    'prompt_additional' => $validated['prompt_settings']['additional'] ?? '',
                ]);
            }

            // Build the prompt for cost estimation
            $sectionsDescription = $this->buildSectionsDescription($validated['sections']);
            $systemPrompt = $this->buildSystemPrompt($validated['prompt'], $sectionsDescription, $validated['template']);
            
            // Check for image sections
            $imageSections = [];
            foreach ($validated['sections'] as $section) {
                $type = strtolower($section['type']);
                if (in_array($type, ['image', 'graphic', 'chart'])) {
                    $width = $section['dimensions']['sectionWidthPx'] ?? 1024;
                    $height = $section['dimensions']['sectionHeightPx'] ?? 1024;
                    
                    // Determine image size based on dimensions
                    if ($width > $height * 1.3) {
                        $size = '1792x1024';
                    } elseif ($height > $width * 1.3) {
                        $size = '1024x1792';
                    } else {
                        $size = '1024x1024';
                    }
                    
                    $imageSections[] = ['size' => $size];
                }
            }
            
            // Calculate estimated cost
            $costEstimate = AICostCalculator::estimateTotalCost($systemPrompt, $imageSections);
            
            // If not confirmed, return cost estimate and require confirmation
            if (!($validated['confirmed'] ?? false)) {
                // Create preliminary price log entry
                $priceLog = AiPriceLog::create([
                    'session' => session()->getId(),
                    'document_id' => $validated['document_id'] ?? null,
                    'user_email' => Auth::check() ? Auth::user()->email : null,
                    'request_type' => 'gemini',
                    'model_name' => config('services.gemini.model'),
                    'input_tokens' => $costEstimate['text_generation']['input_tokens'],
                    'output_tokens' => $costEstimate['text_generation']['output_tokens'],
                    'total_tokens' => $costEstimate['text_generation']['total_tokens'],
                    'image_count' => $costEstimate['image_generation']['count'],
                    'estimated_cost_usd' => $costEstimate['total_cost_usd'],
                    'prompt_preview' => substr($validated['prompt'], 0, 200),
                    'status' => 'estimated',
                ]);
                
                return response()->json([
                    'requires_confirmation' => true,
                    'cost_estimate' => $costEstimate,
                    'price_log_id' => $priceLog->id,
                ]);
            }

            // Create AI request record
            $aiRequest = AiRequest::create([
                'session' => session()->getId(),
                'email' => Auth::check() ? Auth::user()->email : null,
                'template' => $validated['template'] ?? null,
                'prompt' => $validated['prompt'],
                'sections' => $validated['sections'],
                'model' => $validated['model'] ?? 'gpt-4',
            ]);

            // Call Gemini API (systemPrompt and sectionsDescription already built above)
            $chatClient = new DeveloperChatClient();
            $response = $chatClient->chat(
                [
                    ['role' => 'user', 'content' => $systemPrompt]
                ],
                temperature: 0.7,
                options: ['timeout' => 60]
            );

            $generatedContent = $response['reply'] ?? 'No content generated';
            
            // Extract token usage from response and calculate actual cost
            $usageMetadata = $response['response']['usageMetadata'] ?? null;
            $actualCost = null;
            $actualTokens = null;
            
            if ($usageMetadata) {
                $actualCost = AICostCalculator::calculateGeminiCost($usageMetadata);
                $actualTokens = $actualCost;
                Log::info('Gemini API usage', ['metadata' => $usageMetadata, 'cost' => $actualCost]);
            }
            
            // Parse the generated content
            $parsedSections = $this->parseGeminiResponse($generatedContent);
            
            // Save the AI response
            $aiResponse = AiResponse::create([
                'ai_request_id' => $aiRequest->id,
                'session' => session()->getId(),
                'document_id' => $validated['document_id'] ?? null,
                'user_email' => Auth::check() ? Auth::user()->email : null,
                'response_payload' => $response['response'],
                'parsed_sections' => $parsedSections,
            ]);
            
            // Generate images for image/graphic/chart sections
            $generatedImages = $this->generateImagesForSections(
                $aiResponse->id,
                $validated['sections'],
                $validated['prompt'],
                $validated['document_id'] ?? null
            );
            
            // Log actual cost to price log
            $totalImageCost = 0;
            foreach ($imageSections as $imgSection) {
                $imgCost = AICostCalculator::calculateDalleCost($imgSection['size'], 1);
                $totalImageCost += $imgCost['cost_usd'];
            }
            
            $finalCost = ($actualCost ? $actualCost['cost_usd'] : $costEstimate['text_generation']['cost_usd']) + $totalImageCost;
            
            // Update status to completed with actual costs
            AiPriceLog::create([
                'session' => session()->getId(),
                'document_id' => $validated['document_id'] ?? null,
                'user_email' => Auth::check() ? Auth::user()->email : null,
                'request_type' => 'gemini',
                'model_name' => config('services.gemini.model'),
                'input_tokens' => $actualTokens['input_tokens'] ?? $costEstimate['text_generation']['input_tokens'],
                'output_tokens' => $actualTokens['output_tokens'] ?? $costEstimate['text_generation']['output_tokens'],
                'total_tokens' => $actualTokens['total_tokens'] ?? $costEstimate['text_generation']['total_tokens'],
                'image_count' => count($imageSections),
                'estimated_cost_usd' => $costEstimate['total_cost_usd'],
                'cost_usd' => $finalCost,
                'prompt_preview' => substr($validated['prompt'], 0, 200),
                'status' => 'completed',
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Content generated successfully',
                'request_id' => $aiRequest->id,
                'response_id' => $aiResponse->id,
                'data' => [
                    'prompt' => $validated['prompt'],
                    'sections_count' => count($validated['sections']),
                    'generated_content' => $generatedContent,
                    'parsed_sections' => $parsedSections,
                    'sections' => $validated['sections'],
                    'generated_images' => $generatedImages,
                ]
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Server error: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function buildSectionsDescription(array $sections): string
    {
        $description = "Document Structure:\n\n";
        
        foreach ($sections as $index => $section) {
            $description .= "Section " . ($index + 1) . ":\n";
            $description .= "  - Type: {$section['type']}\n";
            $description .= "  - Name: {$section['name']}\n";
            $description .= "  - Height: {$section['height']}% of page\n";
            $description .= "  - Width: {$section['width']}% of page\n";
            $description .= "  - Page: {$section['page']}\n";
            
            if (isset($section['dimensions'])) {
                $dims = $section['dimensions'];
                $description .= "  - Dimensions: {$dims['sectionWidthPx']}px × {$dims['sectionHeightPx']}px\n";
            }
            
            $description .= "\n";
        }
        
        return $description;
    }

    private function buildSystemPrompt(string $userPrompt, string $sectionsDescription, ?string $template): string
    {
        return <<<PROMPT
You are a professional document content generator. Your task is to generate content for a PDF document based on the user's request and the document's structure.

User Request: {$userPrompt}

Template Type: {$template}

{$sectionsDescription}

CRITICAL RENDERING INFORMATION:
- Font: Times New Roman 14px
- All text will be rendered using Times New Roman at 14px font size
- You MUST generate enough text to COMPLETELY FILL each section
- Calculate text length based on the pixel dimensions provided for each section
- Estimate approximately 7-8 characters per line for Times New Roman 14px at typical section widths
- For a 400px wide section, estimate ~50-60 characters per line
- Line height is approximately 20px for 14px Times New Roman

Instructions:
1. Generate appropriate content for EACH section based on its type and the user's request
2. COMPLETELY FILL each section - generate enough text to fill the entire height and width of each section's pixel dimensions
3. Title sections should still be concise (1-3 lines) but sized appropriately for the section
4. Paragraph sections MUST be long enough to fill the entire section height - do NOT generate short paragraphs
5. For a paragraph section that is 400px tall, generate approximately 20 lines of text
6. For chart/graphic sections, describe what should be visualized
7. Return the content in JSON format with this structure:

{
  "sections": [
    {
      "section_number": 1,
      "type": "title",
      "content": "Your generated title here"
    },
    {
      "section_number": 2,
      "type": "paragraph",
      "content": "Your generated paragraph here"
    }
  ]
}

Generate engaging, professional content that matches the user's request. Be creative but stay on topic.
PROMPT;
    }

    private function parseGeminiResponse(string $response): ?array
    {
        try {
            // Try to extract JSON from markdown code blocks
            if (preg_match('/```json\s*\n([\s\S]*?)\n```/', $response, $matches)) {
                $jsonStr = $matches[1];
                $parsed = json_decode($jsonStr, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $parsed;
                }
            }
            
            // Try to parse as raw JSON
            if (trim($response)[0] === '{') {
                $parsed = json_decode($response, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $parsed;
                }
            }
            
            // If parsing fails, return the raw response wrapped
            return [
                'raw_response' => $response,
                'parsed' => false
            ];
        } catch (\Exception $e) {
            return [
                'raw_response' => $response,
                'parsed' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function saveSections(Request $request)
    {
        try {
            $validated = $request->validate([
                'document_id' => 'required|string',
                'sections' => 'required|array',
                'page_width' => 'nullable|numeric',
                'page_height' => 'nullable|numeric',
            ]);

            // Delete any existing sections for this document and session
            AiSection::where('document_id', $validated['document_id'])
                ->where('session', session()->getId())
                ->delete();

            // Create new section record
            $aiSection = AiSection::create([
                'document_id' => $validated['document_id'],
                'session' => session()->getId(),
                'sections_data' => $validated['sections'],
                'page_width' => $validated['page_width'] ?? null,
                'page_height' => $validated['page_height'] ?? null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Sections saved successfully',
                'section_id' => $aiSection->id,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error saving sections: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function getSections(Request $request, $documentId)
    {
        try {
            $sections = AiSection::where('document_id', $documentId)
                ->where('session', session()->getId())
                ->latest()
                ->first();

            if (!$sections) {
                return response()->json([
                    'success' => false,
                    'message' => 'No sections found',
                ]);
            }

            // Get the latest AI response for this document
            $latestResponse = AiResponse::where('document_id', $documentId)
                ->where('session', session()->getId())
                ->latest()
                ->first();

            // Get generated images for this document
            $images = AiImage::where('document_id', $documentId)
                ->where('session', session()->getId())
                ->orderBy('section_number')
                ->get()
                ->map(function($image) {
                    return [
                        'id' => $image->id,
                        'section_number' => $image->section_number,
                        'prompt' => $image->prompt,
                        'storage_type' => $image->storage_type,
                        'image_data' => $image->image_data,
                        'mime_type' => $image->mime_type,
                        'width' => $image->width,
                        'height' => $image->height,
                    ];
                });

            return response()->json([
                'success' => true,
                'sections' => $sections->sections_data,
                'page_width' => $sections->page_width,
                'page_height' => $sections->page_height,
                'generated_content' => $latestResponse ? $latestResponse->parsed_sections : null,
                'generated_images' => $images,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error loading sections: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function generateImagesForSections($aiResponseId, $sections, $userPrompt, $documentId)
    {
        $imageSections = array_filter($sections, function($section) {
            return in_array(strtolower($section['type']), ['image', 'graphic', 'chart']);
        });

        Log::info("Found " . count($imageSections) . " image sections to generate");

        $generatedImages = [];
        
        foreach ($imageSections as $section) {
            try {
                Log::info("Generating image for section", ['section' => $section]);
                
                // Build image prompt
                $imagePrompt = $this->buildImagePrompt($userPrompt, $section);
                
                Log::info("Image prompt created", ['prompt' => $imagePrompt]);
                
                // Call Gemini for image generation
                $chatClient = new DeveloperChatClient();
                
                // Calculate image size for DALL-E (must be 1024x1024, 1024x1792, or 1792x1024)
                $width = $section['dimensions']['sectionWidthPx'] ?? 1024;
                $height = $section['dimensions']['sectionHeightPx'] ?? 1024;
                $imageSize = $this->calculateImageSize($width, $height);
                
                Log::info("Calling OpenAI image generation", ['size' => $imageSize]);
                
                $imageResponse = $chatClient->generateImage($imagePrompt, [
                    'size' => $imageSize,
                    'model' => 'dall-e-3',
                    'quality' => 'standard',
                    'timeout' => 120
                ]);
                
                Log::info("Image response received", ['response' => $imageResponse]);
                
                // Check what type of response we got
                $imageData = $imageResponse['base64'] ?? $imageResponse['description'] ?? null;
                $storageType = isset($imageResponse['base64']) ? 'base64' : 'description';
                
                if (!$imageData) {
                    Log::warning("Image generation returned no data", ['response' => $imageResponse]);
                    $imageData = "Image placeholder: {$imagePrompt}";
                    $storageType = 'description';
                }
                
                // Save image to database
                $aiImage = AiImage::create([
                    'ai_response_id' => $aiResponseId,
                    'session' => session()->getId(),
                    'document_id' => $documentId,
                    'section_number' => $section['order'] ?? 0,
                    'prompt' => $imagePrompt,
                    'storage_type' => $storageType,
                    'image_data' => $imageData,
                    'mime_type' => $storageType === 'base64' ? 'image/png' : null,
                    'width' => $section['dimensions']['sectionWidthPx'] ?? null,
                    'height' => $section['dimensions']['sectionHeightPx'] ?? null,
                ]);
                
                Log::info("Image saved to database", ['image_id' => $aiImage->id, 'storage_type' => $storageType]);
                
                $generatedImages[] = [
                    'section_number' => $section['order'] ?? 0,
                    'section_type' => $section['type'],
                    'image_id' => $aiImage->id,
                    'storage_type' => $aiImage->storage_type,
                ];
                
            } catch (\Exception $e) {
                Log::error("Image generation failed for section", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'section' => $section
                ]);
                $generatedImages[] = [
                    'section_number' => $section['order'] ?? 0,
                    'section_type' => $section['type'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ];
            }
        }
        
        Log::info("Image generation complete", ['count' => count($generatedImages)]);
        
        return $generatedImages;
    }

    private function buildImagePrompt($userPrompt, $section)
    {
        $sectionType = $section['type'] ?? 'image';
        $sectionName = $section['name'] ?? $sectionType;
        $width = isset($section['dimensions']['sectionWidthPx']) ? round($section['dimensions']['sectionWidthPx']) : null;
        $height = isset($section['dimensions']['sectionHeightPx']) ? round($section['dimensions']['sectionHeightPx']) : null;
        
        // Get custom prompt settings from session if available
        $style = session('prompt_style', 'modern and professional');
        $quality = session('prompt_quality', 'high-quality, photorealistic');
        $additional = session('prompt_additional', '');
        
        $prompt = "Generate a professional {$sectionType} for a document about: {$userPrompt}. " .
                  "Section name: {$sectionName}. ";
        
        if ($width && $height) {
            $prompt .= "Image dimensions: {$width}px × {$height}px. ";
        }
        
        $prompt .= "The image should be {$quality}, and directly relevant to the content. " .
                   "Style: {$style}.";
        
        // Add additional instructions if provided
        if (!empty($additional)) {
            $prompt .= " {$additional}";
        }
        
        return $prompt;
    }

    private function calculateAspectRatio($section)
    {
        if (!isset($section['dimensions'])) return '1:1';
        
        $width = $section['dimensions']['sectionWidthPx'] ?? 1;
        $height = $section['dimensions']['sectionHeightPx'] ?? 1;
        
        if ($width <= 0 || $height <= 0) return '1:1';
        
        $ratio = $width / $height;
        
        if ($ratio > 1.5) return '16:9';
        if ($ratio > 1.2) return '4:3';
        if ($ratio < 0.8) return '9:16';
        return '1:1';
    }

    private function calculateImageSize($width, $height)
    {
        // DALL-E 3 only supports: 1024x1024, 1024x1792 (portrait), 1792x1024 (landscape)
        $ratio = $width / $height;
        
        if ($ratio > 1.3) {
            // Landscape
            return '1792x1024';
        } elseif ($ratio < 0.77) {
            // Portrait
            return '1024x1792';
        } else {
            // Square
            return '1024x1024';
        }
    }

    public function deleteSections(Request $request, $documentId)
    {
        try {
            $session = session()->getId();
            
            $deleted = AiSection::where('document_id', $documentId)
                ->where('session', $session)
                ->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Sections deleted successfully',
                'deleted_count' => $deleted
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting sections: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getImages(Request $request, $responseId)
    {
        try {
            $images = AiImage::where('ai_response_id', $responseId)
                ->orderBy('section_number')
                ->get();
            
            return response()->json([
                'success' => true,
                'images' => $images,
                'count' => $images->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error loading images: ' . $e->getMessage()
            ], 500);
        }
    }
    
    public function getPriceLog(Request $request)
    {
        try {
            $session = session()->getId();
            $documentId = $request->query('document_id');
            
            $query = AiPriceLog::where('session', $session);
            
            if ($documentId) {
                $query->where('document_id', $documentId);
            }
            
            $logs = $query->orderBy('created_at', 'desc')
                ->take(50) // Limit to last 50 requests
                ->get();
            
            return response()->json([
                'success' => true,
                'logs' => $logs,
                'count' => $logs->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error loading price log: ' . $e->getMessage()
            ], 500);
        }
    }
    
    public function addToPdf(Request $request)
    {
        try {
            $validated = $request->validate([
                'document_id' => 'required|integer',
                'images' => 'required|array',
                'images.*' => 'required|string', // base64 encoded images
            ]);
            
            $documentId = $validated['document_id'];
            $images = $validated['images'];
            
            // Find the document
            $document = \App\Models\Document::findOrFail($documentId);
            $originalPath = Storage::path($document->path);
            
            if (!file_exists($originalPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Original PDF file not found'
                ], 404);
            }
            
            // Create output path for modified PDF
            $outputPath = tempnam(sys_get_temp_dir(), 'ai_merged_') . '.pdf';
            
            // Prepare images JSON for Python script
            // Write JSON to a temporary file to avoid command line length limits
            $jsonTempFile = tempnam(sys_get_temp_dir(), 'ai_images_') . '.json';
            file_put_contents($jsonTempFile, json_encode($images));
            
            // Call Python script - pass the JSON file path instead of the JSON string
            $pythonScript = base_path('python/pdf-editor/add_ai_pages_to_pdf.py');
            
            $command = sprintf(
                '/usr/bin/python3 %s %s %s %s 2>&1',
                escapeshellarg($pythonScript),
                escapeshellarg($originalPath),
                escapeshellarg($outputPath),
                escapeshellarg($jsonTempFile)
            );
            
            exec($command, $output, $returnCode);
            
            // Clean up the temporary JSON file
            if (file_exists($jsonTempFile)) {
                unlink($jsonTempFile);
            }
            
            if ($returnCode !== 0 || !file_exists($outputPath)) {
                Log::error('AI pages addition failed', [
                    'document_id' => $documentId,
                    'output' => implode("\n", $output),
                    'return_code' => $returnCode
                ]);
                
                if (file_exists($outputPath)) {
                    unlink($outputPath);
                }
                
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to add pages to PDF',
                    'error' => implode("\n", $output)
                ], 500);
            }
            
            // Parse the result from Python script
            $result = json_decode(end($output), true);
            
            if (!$result || !isset($result['success']) || !$result['success']) {
                if (file_exists($outputPath)) {
                    unlink($outputPath);
                }
                
                return response()->json([
                    'success' => false,
                    'message' => 'Python script reported failure',
                    'details' => $result['error'] ?? 'Unknown error'
                ], 500);
            }
            
            // Replace the original PDF with the new one
            $originalBackupPath = $originalPath . '.backup_' . time();
            
            // Backup original
            copy($originalPath, $originalBackupPath);
            
            // Replace with new PDF
            if (!copy($outputPath, $originalPath)) {
                // Restore from backup if copy failed
                copy($originalBackupPath, $originalPath);
                unlink($originalBackupPath);
                unlink($outputPath);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to replace PDF file'
                ], 500);
            }
            
            // Clean up
            unlink($outputPath);
            unlink($originalBackupPath);
            
            // Update document size
            $document->size_bytes = filesize($originalPath);
            $document->save();
            
            Log::info('AI pages added successfully', [
                'document_id' => $documentId,
                'pages_added' => $result['pages_added'] ?? count($images)
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Pages added to PDF successfully',
                'pages_added' => $result['pages_added'] ?? count($images)
            ]);
        } catch (\Exception $e) {
            Log::error('Error in addToPdf', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error adding pages to PDF: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getImageById($imageId)
    {
        try {
            $image = \DB::table('ai_images')->where('id', $imageId)->first();
            
            if (!$image) {
                return response()->json([
                    'error' => 'Image not found'
                ], 404);
            }
            
            return response()->json([
                'id' => $image->id,
                'image_data' => $image->image_data,
                'storage_type' => $image->storage_type,
                'mime_type' => $image->mime_type,
                'width' => $image->width,
                'height' => $image->height,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching image by ID', [
                'image_id' => $imageId,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'error' => 'Failed to fetch image'
            ], 500);
        }
    }
}
