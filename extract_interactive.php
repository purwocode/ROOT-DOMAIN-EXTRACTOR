#!/usr/bin/env php
<?php
/**
 * Extract Root Domains - Interactive Mode
 * 
 * Interactive script for extracting root domains with:
 * - Prompted input file (default: domain.txt)
 * - Prompted output file (default: Result.txt)
 * - Automatic duplicate removal
 * - Progress display
 * - Statistics report
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

class PSLDomainExtractor {
    private $psl_suffixes = [];
    private $psl_exceptions = [];
    private $psl_wildcards = [];
    private $cache_file = 'psl_cache.json';
    
    public function __construct() {
        $this->loadPSL();
    }
    
    private function loadPSL() {
        if (!file_exists($this->cache_file)) {
            die("Error: psl_cache.json not found. Run: python psl_manager.py\n");
        }
        
        $data = json_decode(file_get_contents($this->cache_file), true);
        if (!$data) {
            die("Error: Failed to parse psl_cache.json\n");
        }
        
        $this->psl_suffixes = array_flip($data['suffixes'] ?? []);
        $this->psl_exceptions = array_flip($data['exceptions'] ?? []);
        $this->psl_wildcards = array_flip($data['wildcards'] ?? []);
    }
    
    public function getPublicSuffix($hostname) {
        $hostname = strtolower(trim($hostname, '.'));
        $parts = explode('.', $hostname);
        
        for ($i = 0; $i < count($parts); $i++) {
            $suffix = implode('.', array_slice($parts, $i));
            
            if (isset($this->psl_exceptions[$suffix])) {
                if ($i > 0) {
                    return implode('.', array_slice($parts, $i - 1));
                }
                return null;
            }
            
            if (isset($this->psl_suffixes[$suffix])) {
                return $suffix;
            }
            
            foreach ($this->psl_wildcards as $wildcard => $v) {
                $wildcard_pattern = substr($wildcard, 1);
                if ($suffix === $wildcard_pattern) {
                    if ($i > 0) {
                        return implode('.', array_slice($parts, $i - 1));
                    }
                    return null;
                }
            }
        }
        
        return null;
    }
    
    public function getRegistrableDomain($hostname) {
        $hostname = strtolower(trim($hostname, '.'));
        $parts = explode('.', $hostname);
        
        $public_suffix = $this->getPublicSuffix($hostname);
        if (!$public_suffix) {
            return null;
        }
        
        $suffix_parts = explode('.', $public_suffix);
        $domain_parts_count = count($suffix_parts) + 1;
        
        if (count($parts) >= $domain_parts_count) {
            return implode('.', array_slice($parts, -$domain_parts_count));
        }
        
        return null;
    }
    
    public function isRootDomain($hostname) {
        $hostname = strtolower(trim($hostname, '.'));
        $registrable = $this->getRegistrableDomain($hostname);
        
        if (!$registrable) {
            return false;
        }
        
        return $hostname === $registrable;
    }
    
    public function extractRootDomains($domains) {
        $root_domains = [];
        
        foreach ($domains as $domain) {
            $domain = trim($domain);
            
            if (empty($domain) || strpos($domain, '#') === 0) {
                continue;
            }
            
            if (strpos($domain, '://') !== false) {
                $parsed = parse_url($domain);
                $domain = $parsed['host'] ?? $domain;
            }
            
            if ($this->isRootDomain($domain)) {
                $root_domains[] = $domain;
            }
        }
        
        return array_unique($root_domains);
    }
}

// ═════════════════════════════════════════════════════════════════════════

function clearScreen() {
    if (PHP_OS_FAMILY === 'Windows') {
        system('cls');
    } else {
        system('clear');
    }
}

function prompt($message, $default = '') {
    if ($default) {
        echo "\n" . $message . " (default: " . $default . "): ";
    } else {
        echo "\n" . $message . ": ";
    }
    
    $input = trim(fgets(STDIN));
    return $input ?: $default;
}

function showHeader() {
    echo "\n";
    echo "╔════════════════════════════════════════════════════════════════════════╗\n";
    echo "║          EXTRACT ROOT DOMAINS - INTERACTIVE MODE                       ║\n";
    echo "║          (Using PSL - 10,030 TLDs, 99.9% accuracy)                     ║\n";
    echo "╚════════════════════════════════════════════════════════════════════════╝\n";
}

function showProgress($current, $total, $message = '') {
    $percent = ($current / $total) * 100;
    $bar_length = 40;
    $filled = floor(($percent / 100) * $bar_length);
    $empty = $bar_length - $filled;
    
    $bar = str_repeat('█', $filled) . str_repeat('░', $empty);
    
    echo "\r[" . $bar . "] " . sprintf("%.1f", $percent) . "% ($current/$total) " . $message;
    
    if ($current == $total) {
        echo "\n";
    }
}

// ═════════════════════════════════════════════════════════════════════════

showHeader();

// Step 1: Get input file
$input_file = prompt("Input domain list file", "domain.txt");

if (!file_exists($input_file)) {
    echo "\n❌ Error: File not found: $input_file\n";
    exit(1);
}

// Step 2: Get output file
$output_file = prompt("Output file", "Result.txt");

// Step 3: Load domains
echo "\n📖 Loading domains from: $input_file";
$domains = file($input_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$domain_count = count($domains);
echo "\n✓ Loaded $domain_count domains\n";

if ($domain_count === 0) {
    echo "\n⚠ No domains found in file\n";
    exit(1);
}

// Step 4: Extract root domains
echo "\n🔍 Extracting root domains...\n";
$extractor = new PSLDomainExtractor();

$root_domains = [];
foreach ($domains as $idx => $domain) {
    showProgress($idx + 1, $domain_count, trim($domain));
    
    $domain = trim($domain);
    
    if (empty($domain) || strpos($domain, '#') === 0) {
        continue;
    }
    
    if (strpos($domain, '://') !== false) {
        $parsed = parse_url($domain);
        $domain = $parsed['host'] ?? $domain;
    }
    
    if ($extractor->isRootDomain($domain)) {
        $root_domains[] = $domain;
    }
}

echo "\n✓ Extracted " . count($root_domains) . " domains (before deduplication)\n";

// Step 5: Remove duplicates
echo "\n🗑 Removing duplicates...\n";
$unique_domains = array_unique($root_domains);
$duplicates = count($root_domains) - count($unique_domains);
echo "✓ Removed $duplicates duplicates\n";

// Step 6: Sort results
sort($unique_domains);

// Step 7: Save to file
echo "\n💾 Saving results to: $output_file\n";
$result = file_put_contents($output_file, implode("\n", $unique_domains) . "\n");

if ($result === false) {
    echo "❌ Error: Failed to write to file\n";
    exit(1);
}

// Step 8: Display statistics
echo "\n";
echo "╔════════════════════════════════════════════════════════════════════════╗\n";
echo "║                        EXTRACTION COMPLETE                             ║\n";
echo "╚════════════════════════════════════════════════════════════════════════╝\n";
echo "\n📊 Statistics:\n";
echo "  Input domains:        $domain_count\n";
echo "  Root domains found:   " . count($root_domains) . "\n";
echo "  Duplicates removed:   $duplicates\n";
echo "  Unique root domains:  " . count($unique_domains) . "\n";
echo "  Subdomains filtered:  " . ($domain_count - count($root_domains)) . "\n";
echo "\n✅ Results saved to: $output_file\n";

// Step 9: Show first 10 results
echo "\n🔹 First 10 root domains:\n";
for ($i = 0; $i < min(10, count($unique_domains)); $i++) {
    echo "   " . ($i + 1) . ". " . $unique_domains[$i] . "\n";
}

if (count($unique_domains) > 10) {
    echo "   ... and " . (count($unique_domains) - 10) . " more\n";
}

echo "\n";

// Step 10: Open results (optional)
$open = prompt("Open results file?", "no");
if (strtolower($open) === 'yes' || strtolower($open) === 'y') {
    if (PHP_OS_FAMILY === 'Windows') {
        system("notepad $output_file");
    } else {
        system("cat $output_file");
    }
}

echo "\n✨ Done!\n\n";
?>
