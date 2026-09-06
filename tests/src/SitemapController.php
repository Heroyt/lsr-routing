<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Tests\Mockup;

use Lsr\Core\Routing\Attributes\Meta;
use Lsr\Core\Routing\Attributes\Route;
use Lsr\Core\Routing\Attributes\Sitemap;
use Lsr\Core\Routing\Attributes\SitemapExclude;
use Lsr\Core\Routing\Sitemap\SitemapChangeFrequency;
use Lsr\Enums\RequestMethod;

final class SitemapController
{
    #[Route(path: '/attribute/one')]
    #[Route(path: '/attribute/two')]
    #[Route(method: RequestMethod::POST, path: '/attribute/one')]
    #[Sitemap('articles', priority: 0.6, changefreq: SitemapChangeFrequency::WEEKLY)]
    #[Meta(['section' => 'articles'])]
    public function article(): void
    {
    }

    #[Route(path: '/attribute/excluded')]
    #[SitemapExclude]
    #[Sitemap('excluded')]
    public function excluded(): void
    {
    }

    #[Route(method: RequestMethod::POST, path: '/attribute/audit', name: 'attribute.audit')]
    #[Meta(['audit' => 'write'])]
    public function audit(): void
    {
    }
}
