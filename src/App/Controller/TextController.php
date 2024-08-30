<?php

namespace OpenCCK\App\Controller;

class TextController extends AbstractIPListController {
    /**
     * @return string
     */
    public function getBody(): string {
        $this->setHeaders(['content-type' => 'text/plain']);

        $site = $this->request->getQueryParameter('site') ?? '';
        $data = $this->request->getQueryParameter('data') ?? '';
        if ($data == '') {
            return "# Error: The 'data' GET parameter is required in the URL to access this page, but it cannot have the value 'All'";
        }

        $response = '';
        if ($site == '') {
            foreach ($this->service->sites as $site) {
                $response .= $this->render($site->name, $site->$data);
            }
            return $response;
        } else {
            return $this->render($site, $this->service->sites[$site]->$data);
        }
    }

    private function render(string $name, iterable $array): string {
        $response = '# ' . $name . ' ' . date('Y-m-d H:i:s') . "\n";
        foreach ($array as $item) {
            $response .= $item . "\n";
        }
        return $response;
    }
}
