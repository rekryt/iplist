<?php

namespace OpenCCK\App\Controller;

class MikrotikController extends AbstractIPListController {
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
        $response = '/ip firewall address-list' . "\n";
        if ($site == '') {
            foreach ($this->service->sites as $site) {
                $response .= $this->render($site->name, $site->$data);
            }
            return $response;
        } else {
            return $response . $this->render($site, $this->service->sites[$site]->$data);
        }
    }

    /**
     * @param string $site
     * @param iterable $array
     * @return string
     */
    private function render(string $site, iterable $array): string {
        $response = '';
        $listName = str_replace(' ', '', $site);
        foreach ($array as $item) {
            $response .= 'add list=' . $listName . ' address=' . $item . ' comment=' . $listName . "\n";
        }
        return $response;
    }
}
