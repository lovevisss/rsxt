<?php

namespace App\Services;

use HTMLPurifier;
use HTMLPurifier_Config;

class AssessmentSummary
{
    private HTMLPurifier $purifier;

    public function __construct()
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', 'p,br,h2,h3,strong,b,em,i,u,ul,ol,li,blockquote');
        $config->set('Cache.DefinitionImpl', null);
        $this->purifier = new HTMLPurifier($config);
    }

    public function clean(?string $html): string
    {
        return $this->purifier->purify($html ?? '');
    }

    public function hasContent(?string $html): bool
    {
        return trim(html_entity_decode(strip_tags($this->clean($html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')) !== '';
    }
}
