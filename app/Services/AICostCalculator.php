<?php

namespace App\Services;

class AICostCalculator
{
    /**
     * Estimate Gemini API cost based on prompt text before making the request
     * Uses ~4 characters per token rule of thumb
     * 
     * @param string $promptText The full prompt text
     * @param int $estimatedOutputTokens Expected output size (default 500)
     * @return array ['input_tokens' => int, 'output_tokens' => int, 'total_tokens' => int, 'cost_usd' => float]
     */
    public static function estimateGeminiCost(string $promptText, int $estimatedOutputTokens = 500): array
    {
        $pricing = config('services.gemini.pricing');
        
        // Estimate input tokens: ~4 characters per token
        $inputTokens = (int) ceil(strlen($promptText) / 4);
        $outputTokens = $estimatedOutputTokens;
        $totalTokens = $inputTokens + $outputTokens;
        
        // Calculate cost
        $inputCost = ($inputTokens / 1000000) * $pricing['input_per_million'];
        $outputCost = ($outputTokens / 1000000) * $pricing['output_per_million'];
        $totalCost = $inputCost + $outputCost;
        
        return [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $totalTokens,
            'cost_usd' => round($totalCost, 6),
            'breakdown' => [
                'input_cost' => round($inputCost, 6),
                'output_cost' => round($outputCost, 6),
            ],
        ];
    }
    
    /**
     * Calculate actual Gemini cost from API response metadata
     * 
     * @param array $usageMetadata The usageMetadata from Gemini API response
     * @return array ['input_tokens' => int, 'output_tokens' => int, 'total_tokens' => int, 'cost_usd' => float]
     */
    public static function calculateGeminiCost(array $usageMetadata): array
    {
        $pricing = config('services.gemini.pricing');
        
        $inputTokens = $usageMetadata['promptTokenCount'] ?? 0;
        $outputTokens = $usageMetadata['candidatesTokenCount'] ?? 0;
        $totalTokens = $usageMetadata['totalTokenCount'] ?? ($inputTokens + $outputTokens);
        
        // Calculate cost
        $inputCost = ($inputTokens / 1000000) * $pricing['input_per_million'];
        $outputCost = ($outputTokens / 1000000) * $pricing['output_per_million'];
        $totalCost = $inputCost + $outputCost;
        
        return [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $totalTokens,
            'cost_usd' => round($totalCost, 6),
            'breakdown' => [
                'input_cost' => round($inputCost, 6),
                'output_cost' => round($outputCost, 6),
            ],
        ];
    }
    
    /**
     * Calculate DALL-E 3 cost based on image dimensions
     * 
     * @param string $size Image size (e.g., '1024x1024', '1024x1792', '1792x1024')
     * @param int $count Number of images
     * @return array ['image_size' => string, 'image_count' => int, 'cost_usd' => float]
     */
    public static function calculateDalleCost(string $size, int $count = 1): array
    {
        $pricing = config('services.openai.pricing');
        
        // Normalize size format
        $size = strtolower(str_replace(['×', ' '], 'x', $size));
        
        // Get price per image
        $pricePerImage = match($size) {
            '1024x1024' => $pricing['dalle3_1024x1024'],
            '1024x1792' => $pricing['dalle3_1024x1792'],
            '1792x1024' => $pricing['dalle3_1792x1024'],
            default => $pricing['dalle3_1024x1024'], // Default to standard square
        };
        
        $totalCost = $pricePerImage * $count;
        
        return [
            'image_size' => $size,
            'image_count' => $count,
            'cost_per_image' => $pricePerImage,
            'cost_usd' => round($totalCost, 6),
        ];
    }
    
    /**
     * Estimate total cost for a chat request with images
     * 
     * @param string $promptText The prompt text
     * @param array $imageSections Array of image section data with dimensions
     * @return array Complete cost breakdown
     */
    public static function estimateTotalCost(string $promptText, array $imageSections = []): array
    {
        // Estimate text generation cost
        $textCost = self::estimateGeminiCost($promptText);
        
        // Calculate image generation costs
        $imageCost = 0;
        $imageCount = count($imageSections);
        $imageBreakdown = [];
        
        foreach ($imageSections as $section) {
            $size = $section['size'] ?? '1024x1024';
            $cost = self::calculateDalleCost($size, 1);
            $imageCost += $cost['cost_usd'];
            $imageBreakdown[] = $cost;
        }
        
        $totalCost = $textCost['cost_usd'] + $imageCost;
        
        return [
            'text_generation' => $textCost,
            'image_generation' => [
                'count' => $imageCount,
                'cost_usd' => round($imageCost, 6),
                'breakdown' => $imageBreakdown,
            ],
            'total_cost_usd' => round($totalCost, 6),
        ];
    }
}
