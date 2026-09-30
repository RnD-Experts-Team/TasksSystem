<?php

namespace App\Services\Roadmap;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Inline\Text;

/**
 * Admin-authored markdown -> sanitised HTML (official responses, changelog bodies, previews).
 *
 *  - raw HTML is stripped (html_input=strip), unsafe link schemes (javascript:, data:, vbscript:)
 *    lose their href (allow_unsafe_links=false)
 *  - external links get rel="nofollow noopener noreferrer" target="_blank"
 *  - images are kept only when their URL is https, otherwise they degrade to their alt text
 *  - input is capped at 20 KB and nesting at 20 levels
 */
class MarkdownRenderer
{
    public const MAX_BYTES = 20480;

    public function render(?string $markdown): string
    {
        $markdown = trim((string) $markdown);
        if ($markdown === '') {
            return '';
        }

        if (strlen($markdown) > self::MAX_BYTES) {
            $markdown = mb_strcut($markdown, 0, self::MAX_BYTES, 'UTF-8');
        }

        return trim($this->converter()->convert($markdown)->getContent());
    }

    private function converter(): MarkdownConverter
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            'external_link' => [
                'internal_hosts' => $this->internalHosts(),
                'open_in_new_window' => true,
                'html_class' => '',
                'nofollow' => 'external',
                'noopener' => 'external',
                'noreferrer' => 'external',
            ],
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new StrikethroughExtension);
        $environment->addExtension(new TableExtension);
        $environment->addExtension(new ExternalLinkExtension);

        // Runs after the external-link processor; degrade non-https images to their alt text.
        $environment->addEventListener(DocumentParsedEvent::class, function (DocumentParsedEvent $event) {
            $images = [];
            foreach ($event->getDocument()->iterator() as $node) {
                if ($node instanceof Image) {
                    $images[] = $node;
                }
            }

            foreach ($images as $image) {
                if (preg_match('#^https://#i', $image->getUrl()) === 1) {
                    continue;
                }

                $alt = '';
                foreach ($image->children() as $child) {
                    if ($child instanceof Text) {
                        $alt .= $child->getLiteral();
                    }
                }

                if ($alt !== '') {
                    $image->replaceWith(new Text($alt));
                } else {
                    $image->detach();
                }
            }
        }, -10);

        return new MarkdownConverter($environment);
    }

    /** @return string[] */
    private function internalHosts(): array
    {
        $host = parse_url((string) config('roadmap.frontend_url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? [$host] : [];
    }
}
