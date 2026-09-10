#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
PSL Manager: Download, parse, cache, and query Public Suffix List

This module provides efficient domain classification using PSL data.
On first run, downloads PSL from publicsuffix.org and caches it locally.
Subsequent runs load from cache (instant startup).
"""

import os
import json
import requests
import logging
from typing import Optional, Dict, Set, Tuple

logger = logging.getLogger(__name__)

PSL_URL = "https://publicsuffix.org/list/public_suffix_list.dat"
CACHE_FILE = os.path.join(os.path.dirname(__file__), "psl_cache.json")


class PSLManager:
    """Efficient Public Suffix List manager with caching"""
    
    def __init__(self, cache_file: str = CACHE_FILE, use_cache: bool = True):
        """
        Initialize PSL Manager
        
        Args:
            cache_file: Path to cache file
            use_cache: Whether to use cached PSL (True) or force download (False)
        """
        self.cache_file = cache_file
        self.psl_suffixes: Set[str] = set()
        self.psl_exceptions: Set[str] = set()  # Rules starting with !
        self.psl_wildcards: Set[str] = set()   # Rules starting with *
        
        # Try to load from cache first
        if use_cache and os.path.exists(cache_file):
            self._load_from_cache()
        else:
            self._download_and_cache()
    
    def _download_and_cache(self):
        """Download PSL from publicsuffix.org and cache locally"""
        try:
            logger.info("Downloading Public Suffix List...")
            response = requests.get(PSL_URL, timeout=10)
            response.raise_for_status()
            
            self._parse_psl(response.text)
            self._save_to_cache()
            logger.info(f"PSL cached: {len(self.psl_suffixes)} suffixes")
        except Exception as e:
            logger.error(f"Failed to download PSL: {e}")
            raise
    
    def _parse_psl(self, psl_text: str):
        """Parse PSL format into categories"""
        for line in psl_text.split('\n'):
            line = line.strip()
            
            # Skip comments and empty lines
            if not line or line.startswith('//'):
                continue
            
            # Handle exception rules (!)
            if line.startswith('!'):
                self.psl_exceptions.add(line[1:])
            # Handle wildcards (*)
            elif line.startswith('*'):
                self.psl_wildcards.add(line)
            # Regular suffix
            else:
                self.psl_suffixes.add(line)
    
    def _save_to_cache(self):
        """Save PSL to JSON cache"""
        cache_data = {
            'suffixes': list(self.psl_suffixes),
            'exceptions': list(self.psl_exceptions),
            'wildcards': list(self.psl_wildcards)
        }
        
        try:
            with open(self.cache_file, 'w', encoding='utf-8') as f:
                json.dump(cache_data, f)
            logger.info(f"PSL cached to {self.cache_file}")
        except Exception as e:
            logger.warning(f"Failed to cache PSL: {e}")
    
    def _load_from_cache(self):
        """Load PSL from JSON cache"""
        try:
            with open(self.cache_file, 'r', encoding='utf-8') as f:
                cache_data = json.load(f)
            
            self.psl_suffixes = set(cache_data.get('suffixes', []))
            self.psl_exceptions = set(cache_data.get('exceptions', []))
            self.psl_wildcards = set(cache_data.get('wildcards', []))
            
            logger.info(f"Loaded PSL from cache: {len(self.psl_suffixes)} suffixes")
        except Exception as e:
            logger.warning(f"Failed to load from cache: {e}. Downloading...")
            self._download_and_cache()
    
    def get_public_suffix(self, hostname: str) -> Optional[str]:
        """
        Get public suffix for a hostname
        
        Examples:
            get_public_suffix('google.com') → 'com'
            get_public_suffix('example.co.uk') → 'co.uk'
            get_public_suffix('user.github.io') → 'github.io' (exception rule)
        
        Args:
            hostname: Domain name or hostname
        
        Returns:
            Public suffix or None if not found
        """
        hostname = hostname.lower().strip('.')
        parts = hostname.split('.')
        
        # Check from longest to shortest suffix
        for i in range(len(parts)):
            suffix = '.'.join(parts[i:])
            
            # Check exception rules first (highest priority)
            if suffix in self.psl_exceptions:
                # Exception rule matched - public suffix is one level above
                if i > 0:
                    return '.'.join(parts[i-1:])
                return None
            
            # Check exact match
            if suffix in self.psl_suffixes:
                return suffix
            
            # Check wildcards (*.suffix)
            for wildcard in self.psl_wildcards:
                wildcard_pattern = wildcard[1:]  # Remove *
                if suffix == wildcard_pattern:
                    if i > 0:
                        return '.'.join(parts[i-1:])
                    return None
        
        return None
    
    def get_registrable_domain(self, hostname: str) -> Optional[str]:
        """
        Get registrable domain (TLD + 1 level)
        
        Examples:
            get_registrable_domain('google.com') → 'google.com'
            get_registrable_domain('example.co.uk') → 'example.co.uk'
            get_registrable_domain('api.example.com') → 'example.com'
            get_registrable_domain('user.github.io') → 'user.github.io'
        
        Args:
            hostname: Domain name or hostname
        
        Returns:
            Registrable domain or None if not found
        """
        hostname = hostname.lower().strip('.')
        parts = hostname.split('.')
        
        public_suffix = self.get_public_suffix(hostname)
        if not public_suffix:
            return None
        
        # Count how many parts are in public suffix
        suffix_parts = public_suffix.split('.')
        
        # Registrable domain = public suffix + 1 more label
        domain_parts_count = len(suffix_parts) + 1
        
        if len(parts) >= domain_parts_count:
            return '.'.join(parts[-domain_parts_count:])
        
        return None
    
    def is_root_domain(self, hostname: str) -> bool:
        """
        Check if hostname is a root domain (registrable domain with no subdomain)
        
        Examples:
            is_root_domain('google.com') → True
            is_root_domain('api.google.com') → False
            is_root_domain('example.co.uk') → True
            is_root_domain('api.example.co.uk') → False
        
        Args:
            hostname: Domain name or hostname
        
        Returns:
            True if root domain, False if subdomain
        """
        hostname = hostname.lower().strip('.')
        registrable = self.get_registrable_domain(hostname)
        
        if not registrable:
            return False
        
        # Root domain if hostname == registrable domain
        return hostname == registrable


# Global manager instance
_psl_manager: Optional[PSLManager] = None


def init_psl_manager() -> PSLManager:
    """Initialize global PSL manager"""
    global _psl_manager
    if _psl_manager is None:
        _psl_manager = PSLManager()
    return _psl_manager


def get_public_suffix(hostname: str) -> Optional[str]:
    """Get public suffix using global PSL manager"""
    manager = init_psl_manager()
    return manager.get_public_suffix(hostname)


def get_registrable_domain(hostname: str) -> Optional[str]:
    """Get registrable domain using global PSL manager"""
    manager = init_psl_manager()
    return manager.get_registrable_domain(hostname)


def is_root_domain(hostname: str) -> bool:
    """Check if hostname is root domain using global PSL manager"""
    manager = init_psl_manager()
    return manager.is_root_domain(hostname)


if __name__ == "__main__":
    # Test
    logging.basicConfig(level=logging.INFO)
    
    print("Testing PSL Manager...")
    
    test_cases = [
        ('google.com', True),
        ('api.google.com', False),
        ('example.co.uk', True),
        ('api.example.co.uk', False),
        ('user.github.io', True),
        ('repo.user.github.io', False),
    ]
    
    for domain, expected_root in test_cases:
        result = is_root_domain(domain)
        status = "✓" if result == expected_root else "✗"
        print(f"{status} {domain}: is_root={result} (expected {expected_root})")
