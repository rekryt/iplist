<?php

namespace OpenCCK\App\Controller;

use Amp\Http\HttpStatus;
use function Amp\File\isFile;
use function Amp\File\read;
use function mime_content_type;

class ScriptsController extends AbstractIPListController {
    /**
     * @return string
     */
    public function getBody(): string {
        $url = parse_url($this->request->getUri());
        $path = str_replace('..', '', $url['path']);
        $path = implode('/', array_slice(explode('/', $path), 2));
        $path = PATH_ROOT . '/scripts/' . $path;

        if (isFile($path) && ($mimeType = mime_content_type($path))) {
            $this->setHeaders(['content-type' => $mimeType]);
            return read($path);
        }

        $this->setHttpStatus(HttpStatus::NOT_FOUND);
        return '';
    }
}
