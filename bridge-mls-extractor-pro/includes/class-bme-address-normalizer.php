<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Address normalization utility for consistent address matching
 * Version: 1.0.0
 */
class BME_Address_Normalizer {
    
    /**
     * Common street type abbreviations
     */
    private static $street_types = [
        'avenue' => 'ave',
        'av' => 'ave',
        'aven' => 'ave',
        'avenu' => 'ave',
        'avn' => 'ave',
        'avnue' => 'ave',
        'boulevard' => 'blvd',
        'boul' => 'blvd',
        'boulv' => 'blvd',
        'circle' => 'cir',
        'circ' => 'cir',
        'circl' => 'cir',
        'court' => 'ct',
        'crt' => 'ct',
        'drive' => 'dr',
        'driv' => 'dr',
        'drv' => 'dr',
        'expressway' => 'expy',
        'express' => 'expy',
        'exp' => 'expy',
        'expr' => 'expy',
        'highway' => 'hwy',
        'highwy' => 'hwy',
        'hiway' => 'hwy',
        'hway' => 'hwy',
        'lane' => 'ln',
        'parkway' => 'pkwy',
        'parkwy' => 'pkwy',
        'pkway' => 'pkwy',
        'pky' => 'pkwy',
        'place' => 'pl',
        'plaza' => 'plz',
        'plza' => 'plz',
        'road' => 'rd',
        'square' => 'sq',
        'sqr' => 'sq',
        'sqre' => 'sq',
        'street' => 'st',
        'strt' => 'st',
        'str' => 'st',
        'terrace' => 'ter',
        'terr' => 'ter',
        'trail' => 'trl',
        'tr' => 'trl',
        'way' => 'way',
        'wy' => 'way'
    ];
    
    /**
     * Common directional abbreviations
     */
    private static $directions = [
        'north' => 'n',
        'south' => 's',
        'east' => 'e',
        'west' => 'w',
        'northeast' => 'ne',
        'northwest' => 'nw',
        'southeast' => 'se',
        'southwest' => 'sw'
    ];
    
    /**
     * Common unit abbreviations
     */
    private static $unit_types = [
        'apartment' => 'apt',
        'building' => 'bldg',
        'floor' => 'fl',
        'suite' => 'ste',
        'unit' => 'unit',
        'room' => 'rm',
        'department' => 'dept'
    ];
    
    /**
     * Normalize an address for consistent matching
     * 
     * @param string $address The address to normalize
     * @return string The normalized address
     */
    public static function normalize($address) {
        if (empty($address)) {
            return '';
        }
        
        // Convert to lowercase for processing
        $normalized = strtolower(trim($address));
        
        // Remove multiple spaces
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        
        // Remove periods after abbreviations
        $normalized = preg_replace('/\.(?=\s|$)/', '', $normalized);
        
        // Normalize street types
        foreach (self::$street_types as $long => $short) {
            $normalized = preg_replace('/\b' . preg_quote($long, '/') . '\b/', $short, $normalized);
        }
        
        // Normalize directions
        foreach (self::$directions as $long => $short) {
            $normalized = preg_replace('/\b' . preg_quote($long, '/') . '\b/', $short, $normalized);
        }
        
        // Normalize unit types
        foreach (self::$unit_types as $long => $short) {
            $normalized = preg_replace('/\b' . preg_quote($long, '/') . '\b/', $short, $normalized);
        }
        
        // Standardize unit/apt/suite separators
        $normalized = preg_replace('/\s*[,#]\s*/', ' ', $normalized);
        
        // Remove trailing commas
        $normalized = rtrim($normalized, ',');
        
        // Convert to title case for consistent storage
        $normalized = self::title_case($normalized);
        
        return $normalized;
    }
    
    /**
     * Create a search-friendly version of the address for fuzzy matching
     * Removes unit numbers and extra details
     * 
     * @param string $address The address to simplify
     * @return string The simplified address
     */
    public static function get_base_address($address) {
        $normalized = self::normalize($address);
        
        // Remove unit/apt/suite information
        $patterns = [
            '/\bapt\s+\S+/i',
            '/\bunit\s+\S+/i',
            '/\bste\s+\S+/i',
            '/\b#\s*\S+/i',
            '/\bfl\s+\S+/i',
            '/\brm\s+\S+/i'
        ];
        
        foreach ($patterns as $pattern) {
            $normalized = preg_replace($pattern, '', $normalized);
        }
        
        // Remove multiple spaces again
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        
        return trim($normalized);
    }
    
    /**
     * Calculate similarity between two addresses
     * Returns a score from 0 to 100
     * 
     * @param string $address1 First address
     * @param string $address2 Second address
     * @return int Similarity score (0-100)
     */
    public static function calculate_similarity($address1, $address2) {
        $norm1 = self::normalize($address1);
        $norm2 = self::normalize($address2);
        
        // If normalized addresses match exactly, return 100
        if ($norm1 === $norm2) {
            return 100;
        }
        
        // Check base addresses (without unit numbers)
        $base1 = self::get_base_address($address1);
        $base2 = self::get_base_address($address2);
        
        if ($base1 === $base2) {
            return 95; // High score for same base address
        }
        
        // Calculate Levenshtein distance-based similarity
        $distance = levenshtein($norm1, $norm2);
        $maxLength = max(strlen($norm1), strlen($norm2));
        
        if ($maxLength == 0) {
            return 0;
        }
        
        $similarity = (1 - ($distance / $maxLength)) * 100;
        
        return max(0, min(100, round($similarity)));
    }
    
    /**
     * Convert string to title case, preserving certain patterns
     * 
     * @param string $string The string to convert
     * @return string The title-cased string
     */
    private static function title_case($string) {
        // Split by spaces
        $words = explode(' ', $string);
        $result = [];
        
        foreach ($words as $word) {
            // Keep certain abbreviations in uppercase
            if (in_array($word, ['ne', 'nw', 'se', 'sw', 'n', 's', 'e', 'w'])) {
                $result[] = strtoupper($word);
            }
            // Keep unit numbers as-is
            elseif (preg_match('/^\d/', $word)) {
                $result[] = $word;
            }
            // Title case other words
            else {
                $result[] = ucfirst($word);
            }
        }
        
        return implode(' ', $result);
    }
    
    /**
     * Get addresses that are similar to the given address
     * Used for fuzzy matching in database queries
     * 
     * @param string $address The address to match
     * @param int $min_similarity Minimum similarity score (0-100)
     * @return array Array of SQL conditions for fuzzy matching
     */
    public static function get_fuzzy_match_conditions($address, $min_similarity = 85) {
        $conditions = [];
        
        // Always include exact normalized match
        $normalized = self::normalize($address);
        $conditions[] = $normalized;
        
        // Include base address match
        $base = self::get_base_address($address);
        if ($base !== $normalized) {
            $conditions[] = $base;
        }
        
        // Create variations for common issues
        // Remove all punctuation version
        $no_punct = preg_replace('/[^\w\s]/', ' ', $normalized);
        $no_punct = preg_replace('/\s+/', ' ', trim($no_punct));
        if ($no_punct !== $normalized) {
            $conditions[] = $no_punct;
        }
        
        // Version with 'street' instead of 'st', etc.
        $expanded = $normalized;
        foreach (self::$street_types as $long => $short) {
            if (strpos($expanded, ' ' . $short) !== false) {
                $expanded = str_replace(' ' . $short, ' ' . $long, $expanded);
                break;
            }
        }
        if ($expanded !== $normalized) {
            $conditions[] = $expanded;
        }
        
        return array_unique($conditions);
    }
}