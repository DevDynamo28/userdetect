<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Maps browser language preferences to Indian states.
 * Source: navigator.languages from the SDK (e.g. ['gu', 'hi', 'en-IN'])
 */
class LanguageStateMapper
{
    /**
     * Language code → primary state mapping.
     * Some languages are spoken in multiple states — we track all.
     */
    private const LANGUAGE_MAP = [
        'gu' => ['states' => ['Gujarat'], 'confidence' => 85],
        'ta' => ['states' => ['Tamil Nadu', 'Puducherry'], 'confidence' => 85],
        'te' => ['states' => ['Telangana', 'Andhra Pradesh'], 'confidence' => 78],
        'mr' => ['states' => ['Maharashtra', 'Goa'], 'confidence' => 82],
        'bn' => ['states' => ['West Bengal', 'Tripura'], 'confidence' => 82],
        'kn' => ['states' => ['Karnataka'], 'confidence' => 85],
        'ml' => ['states' => ['Kerala', 'Lakshadweep'], 'confidence' => 85],
        'pa' => ['states' => ['Punjab', 'Chandigarh'], 'confidence' => 82],
        'or' => ['states' => ['Odisha'], 'confidence' => 85],
        'as' => ['states' => ['Assam'], 'confidence' => 85],
        'mni' => ['states' => ['Manipur'], 'confidence' => 88],
        'kok' => ['states' => ['Goa'], 'confidence' => 80],
        'doi' => ['states' => ['Jammu and Kashmir'], 'confidence' => 80],
        'sat' => ['states' => ['Jharkhand'], 'confidence' => 78],
        'mai' => ['states' => ['Bihar'], 'confidence' => 75],
        'bho' => ['states' => ['Bihar', 'Uttar Pradesh', 'Jharkhand'], 'confidence' => 60],
        'ne' => ['states' => ['Sikkim', 'West Bengal'], 'confidence' => 70],
        'sd' => ['states' => ['Gujarat', 'Rajasthan'], 'confidence' => 55],
        'ur' => ['states' => ['Jammu and Kashmir', 'Uttar Pradesh', 'Telangana'], 'confidence' => 40],
        'hi' => [
            'states' => [
                'Uttar Pradesh',
                'Madhya Pradesh',
                'Bihar',
                'Rajasthan',
                'Chhattisgarh',
                'Jharkhand',
                'Uttarakhand',
                'Haryana',
                'Himachal Pradesh',
                'Delhi',
                'Chandigarh'
            ],
            'confidence' => 20
        ], // Hindi is too widespread for state-level inference
    ];

    /**
     * Regional font → state mapping.
     * If user has regional fonts installed, they likely use that script.
     */
    private const FONT_STATE_MAP = [
        'Shruti' => 'Gujarat',
        'Lohit Gujarati' => 'Gujarat',
        'Noto Sans Gujarati' => 'Gujarat',
        'Gujarati Sangam MN' => 'Gujarat',
        'Lohit Tamil' => 'Tamil Nadu',
        'Noto Sans Tamil' => 'Tamil Nadu',
        'Tamil Sangam MN' => 'Tamil Nadu',
        'InaiMathi' => 'Tamil Nadu',
        'Lohit Telugu' => 'Telangana',
        'Noto Sans Telugu' => 'Telangana',
        'Telugu Sangam MN' => 'Telangana',
        'Mangal' => null, // Devanagari — too broad (Hindi/Marathi)
        'Lohit Devanagari' => null,
        'Noto Sans Devanagari' => null,
        'Vrinda' => 'West Bengal',
        'Lohit Bengali' => 'West Bengal',
        'Noto Sans Bengali' => 'West Bengal',
        'Bangla Sangam MN' => 'West Bengal',
        'Tunga' => 'Karnataka',
        'Lohit Kannada' => 'Karnataka',
        'Noto Sans Kannada' => 'Karnataka',
        'Kannada Sangam MN' => 'Karnataka',
        'Kartika' => 'Kerala',
        'Lohit Malayalam' => 'Kerala',
        'Noto Sans Malayalam' => 'Kerala',
        'Malayalam Sangam MN' => 'Kerala',
        'Raavi' => 'Punjab',
        'Lohit Punjabi' => 'Punjab',
        'Noto Sans Gurmukhi' => 'Punjab',
        'Gurmukhi Sangam MN' => 'Punjab',
        'Kalinga' => 'Odisha',
        'Lohit Odia' => 'Odisha',
        'Noto Sans Oriya' => 'Odisha',
        'Oriya Sangam MN' => 'Odisha',
    ];

    /**
     * Infer state from browser language preferences.
     * Returns null if no strong signal.
     */
    public function inferFromLanguages(array $signals): ?array
    {
        $langAnalysis = $signals['language_analysis'] ?? null;
        if (!$langAnalysis || empty($langAnalysis['regional'])) {
            return null;
        }

        // Get the strongest regional language signal (earliest in preferences = most important)
        $bestMatch = null;
        $bestConfidence = 0;

        foreach ($langAnalysis['regional'] as $langInfo) {
            $code = strtolower(explode('-', $langInfo['code'])[0]);

            if (!isset(self::LANGUAGE_MAP[$code])) {
                continue;
            }

            $mapping = self::LANGUAGE_MAP[$code];
            // Position penalty: languages later in the list are less likely primary
            $positionPenalty = min($langInfo['position'] * 5, 20);
            $adjustedConfidence = $mapping['confidence'] - $positionPenalty;

            if ($adjustedConfidence > $bestConfidence) {
                $bestMatch = $mapping;
                $bestConfidence = $adjustedConfidence;
                $bestMatch['language_code'] = $code;
                $bestMatch['language_name'] = $langInfo['language'];
            }
        }

        if (!$bestMatch || $bestConfidence < 15) {
            return null;
        }

        Log::channel('detection')->info(
            "Language inference: {$bestMatch['language_name']} ({$bestMatch['language_code']}) → " .
            implode('/', $bestMatch['states']) . " (confidence: {$bestConfidence})"
        );

        return [
            'states' => $bestMatch['states'],
            'primary_state' => $bestMatch['states'][0],
            'confidence' => $bestConfidence,
            'language' => $bestMatch['language_name'],
            'language_code' => $bestMatch['language_code'],
        ];
    }

    /**
     * Infer state from detected regional fonts.
     */
    public function inferFromFonts(array $signals): ?array
    {
        $regionalFonts = $signals['regional_fonts'] ?? [];
        if (empty($regionalFonts)) {
            return null;
        }

        $stateCounts = [];
        foreach ($regionalFonts as $font) {
            $state = self::FONT_STATE_MAP[$font] ?? null;
            if ($state) {
                $stateCounts[$state] = ($stateCounts[$state] ?? 0) + 1;
            }
        }

        if (empty($stateCounts)) {
            return null;
        }

        // State with most font matches wins
        arsort($stateCounts);
        $topState = array_key_first($stateCounts);
        $fontCount = $stateCounts[$topState];
        $confidence = min(70, 30 + ($fontCount * 15));

        Log::channel('detection')->info(
            "Font inference: {$fontCount} regional fonts detected → {$topState} (confidence: {$confidence})"
        );

        return [
            'state' => $topState,
            'confidence' => $confidence,
            'font_count' => $fontCount,
            'fonts' => $regionalFonts,
        ];
    }
}
