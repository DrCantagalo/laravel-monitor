<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Support\DomainNormalizer;
use Drcantagalo\LaravelMonitor\Tests\TestCase;

/**
 * laravel-monitor 345: normalização da resposta de "domínio principal"
 * pedida por `monitor:install`/`monitor:update` — ver docblock de
 * `DomainNormalizer`.
 */
class DomainNormalizerTest extends TestCase
{
    public function test_bare_domain_is_lowercased(): void
    {
        $this->assertSame('example.com', DomainNormalizer::normalize('Example.COM'));
    }

    public function test_scheme_is_stripped(): void
    {
        $this->assertSame('example.com', DomainNormalizer::normalize('https://example.com'));
        $this->assertSame('example.com', DomainNormalizer::normalize('http://example.com'));
    }

    public function test_www_prefix_is_stripped(): void
    {
        $this->assertSame('example.com', DomainNormalizer::normalize('https://www.example.com'));
        $this->assertSame('example.com', DomainNormalizer::normalize('www.example.com'));
    }

    public function test_path_and_query_are_stripped(): void
    {
        $this->assertSame('example.com', DomainNormalizer::normalize('example.com/landing?utm_source=x'));
    }

    public function test_port_is_stripped(): void
    {
        $this->assertSame('example.com', DomainNormalizer::normalize('example.com:8080'));
    }

    public function test_subdomain_is_preserved(): void
    {
        $this->assertSame('app.example.com', DomainNormalizer::normalize('https://app.example.com'));
    }

    public function test_composite_tld_is_preserved_in_full(): void
    {
        $this->assertSame('ed.eco.br', DomainNormalizer::normalize('https://ed.eco.br'));
    }

    public function test_empty_value_is_rejected(): void
    {
        $this->assertNull(DomainNormalizer::normalize(''));
        $this->assertNull(DomainNormalizer::normalize('   '));
        $this->assertNull(DomainNormalizer::normalize(null));
    }

    public function test_garbage_value_is_rejected(): void
    {
        $this->assertNull(DomainNormalizer::normalize('not a domain!!'));
        $this->assertNull(DomainNormalizer::normalize('http://'));
    }
}
