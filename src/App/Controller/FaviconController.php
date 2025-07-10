<?php

namespace OpenCCK\App\Controller;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Driver\SocketClientFactory;
use Amp\Http\Server\Request;
use OpenCCK\Infrastructure\Storage\IconsStorage;
use function Amp\File\isFile;
use function Amp\File\read;
use function finfo_close;
use function finfo_file;
use function finfo_open;

class FaviconController extends AbstractIPListController {
    private IconsStorage $storage;

    public function __construct(Request $request, array $headers = []) {
        parent::__construct($request, $headers);

        $this->storage = IconsStorage::getInstance();
    }

    /**
     * @return string
     */
    public function getBody(): string {
        $site = $this->request->getQueryParameter('site');

        if ($this->storage->has($site)) {
            return $this->output($this->storage->get($site));
        }

        if (in_array($site, array_keys($this->service->sites))) {
            $content = file_get_contents('https://' . $site . '/favicon.ico');
            if ($content) {
                $filename = $site . '.ico';
                $this->saveIcon($site, $filename, $content);
                return $this->output($filename);
            }

            $siteUrl = 'https://' . $site . '/';
            $indexBody = file_get_contents('https://' . $site . '/');
            if ($indexBody) {
                $rawUrl = $this->extractFaviconHref($indexBody);
                $url = parse_url($rawUrl);
                $parts = explode('.', $url['path']);
                $ext = end($parts);

                $content = file_get_contents($siteUrl . $url['path']);
                if ($content) {
                    $filename = $site . '.' . $ext;
                    $this->saveIcon($site, $filename, $content);
                    return $this->output($filename);
                }
            }

            $filename = 'blank.png';
            $this->saveIcon($site, $filename);
            return $this->output($filename);
        }

        $this->setHttpStatus(HttpStatus::NOT_FOUND);
        return '';
    }

    /**
     * @param string $html
     * @return ?string
     */
    private function extractFaviconHref(string $html): ?string {
        $dom = new \DOMDocument();

        // Заглушка для подавления ошибок из-за невалидного HTML
        \libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        \libxml_clear_errors();

        $links = $dom->getElementsByTagName('link');

        foreach ($links as $link) {
            $rel = strtolower($link->getAttribute('rel'));
            if (in_array($rel, ['icon', 'shortcut icon', 'alternate icon'])) {
                $href = $link->getAttribute('href');
                return $href !== '' ? $href : null;
            }
        }

        return null;
    }

    /**
     * @param string $site
     * @param string $filename
     * @param ?string $content
     * @return void
     */
    private function saveIcon(string $site, string $filename, ?string $content = null): void {
        $this->storage->set($site, $filename);
        if ($content) {
            file_put_contents(PATH_ROOT . '/storage/icons/' . $filename, $content);
        }
        $this->storage->save();
    }

    /**
     * @param string $path
     * @return string
     */
    private function output(string $filename): string {
        $path = PATH_ROOT . '/storage/icons/' . $filename;
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $contentType = finfo_file($finfo, $path);
        finfo_close($finfo);

        $this->setHeaders([
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Expires' => gmdate('D, d M Y H:i:s', strtotime('+1 year')) . ' GMT',
        ]);
        return read($path);
    }
}
