<?php

namespace OpenCCK\App\Controller;

class JsonController extends AbstractIPListController {
    /**
     * @return string
     */
    public function getBody(): string {
        $this->setHeaders(['content-type' => 'application/json']);

        $site = $this->request->getQueryParameter('site') ?? '';
        $data = $this->request->getQueryParameter('data') ?? '';
        if ($site == '') {
            if ($data == '') {
                return json_encode($this->service->sites);
            } else {
                $result = [];
                foreach ($this->service->sites as $site) {
                    $result[$site->name] = $site->$data;
                }
                return json_encode($result);
            }
        } else {
            if ($data == '') {
                return json_encode($this->service->sites[$site]);
            } else {
                return json_encode($this->service->sites[$site]->$data);
            }
        }
    }
}
