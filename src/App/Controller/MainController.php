<?php

namespace OpenCCK\App\Controller;

use Amp\Http\HttpStatus;
use function Amp\File\isFile;
use function Amp\File\read;
use function finfo_close;
use function finfo_file;
use function finfo_open;

class MainController extends AbstractIPListController {
    /**
     * @return string
     */
    public function getBody(): string {
        $url = parse_url($this->request->getUri());
        $path = str_replace('..', '', $url['path']);

        if ($path === '/index') {
            return $this->renderTemplate('index');
        }

        $path = PATH_ROOT . '/public/' . $path;
        foreach ([$path, $path . 'index.html', $path . '.html', $path . '/index.html'] as $filePath) {
            if (isFile($filePath)) {
                return $this->output($filePath);
            }
        }

        $this->setHttpStatus(HttpStatus::NOT_FOUND);
        return '';
    }

    /**
     * @param string $template
     * @return string
     */
    private function renderTemplate(string $template): string {
        ob_start();
        include PATH_ROOT . '/src/App/Template/' . ucfirst($template) . 'Template.php';
        return ob_get_clean();
    }

    /**
     * @param string $path
     * @return string
     */
    private function output(string $path): string {
        $mimeTypes = [
            'js' => 'application/javascript',
            'json' => 'application/json',
            'css' => 'text/css',
        ];
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (isset($mimeTypes[$ext])) {
            $contentType = $mimeTypes[$ext];
        } else {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $contentType = finfo_file($finfo, $path);
            finfo_close($finfo);
        }

        $this->setHeaders(['content-type' => $contentType]);
        return read($path);
    }
}
