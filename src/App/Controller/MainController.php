<?php

namespace OpenCCK\App\Controller;

class MainController extends AbstractIPListController {
    /**
     * @return string
     */
    public function getBody(): string {
        $this->setHeaders(['content-type' => 'text/html; charset=utf-8']);
        return $this->renderTemplate('index');
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
}
