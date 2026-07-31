<?php

declare(strict_types=1);

namespace OpenCCK\App\Controller;

use OpenCCK\Infrastructure\Codec\DatEncoder;
use OpenCCK\Infrastructure\Codec\GeositeDatWriter;
use OpenCCK\Infrastructure\Task\EncodeDatTask;

/**
 * v2ray/xray `geosite.dat` — список доменов, парный к `geoip.dat`.
 *
 * Структура повторяет GeoipController: те же параметры, та же двойная
 * маркировка (портал + группа), тот же стиль текстов ошибок. Отличие —
 * файл собирается на месте (см. GeositeDatWriter), поэтому ни внешнего
 * бинарника, ни временных файлов здесь нет.
 *
 * @see https://github.com/v2fly/v2ray-core/blob/master/app/router/routercommon/common.proto
 */
class GeositeController extends AbstractIPListController {
    private const DOMAIN_TYPES = [
        'suffix' => GeositeDatWriter::TYPE_ROOT_DOMAIN,
        'full' => GeositeDatWriter::TYPE_FULL,
        'keyword' => GeositeDatWriter::TYPE_PLAIN,
        'regex' => GeositeDatWriter::TYPE_REGEX,
    ];

    /**
     * @return string
     */
    public function getBody(): string {
        $this->setHeaders(['content-type' => 'text/plain']);

        $data = $this->request->getQueryParameter('data') ?? '';
        if ($data == '') {
            return "# Error: The 'data' GET parameter is required in the URL to access this page";
        }
        if ($data !== 'domains') {
            return "# Error: The 'data' GET parameter must be 'domains'";
        }

        $domainTypeName = $this->request->getQueryParameter('domaintype') ?: 'suffix';
        if (!isset(self::DOMAIN_TYPES[$domainTypeName])) {
            return "# Error: The 'domaintype' GET parameter must be 'suffix', 'full', 'keyword' or 'regex'";
        }
        $domainType = self::DOMAIN_TYPES[$domainTypeName];

        // Каждый список пишется дважды — под именем портала и под именем группы,
        // как GeoipController делает для geoip.dat: в конфиге xray будут
        // доступны и `geosite:YOUTUBE.COM`, и `geosite:YOUTUBE`.
        // Фильтры site/group/exclude[*]/wildcard уже применены в getSites().
        $entries = [];
        $domainCount = 0;
        foreach ($this->getSites() as $site) {
            if (!count($site->domains)) {
                continue;
            }
            foreach ([$site->name, $site->group] as $code) {
                $entries[$code] = isset($entries[$code])
                    ? array_merge($entries[$code], $site->domains)
                    : $site->domains;
            }
            $domainCount += count($site->domains) * 2;
        }

        $body = DatEncoder::encode(
            EncodeDatTask::KIND_GEOSITE,
            GeositeDatWriter::payload($entries),
            $domainCount,
            $domainType
        );

        $this->setHeaders([
            'content-type' => 'application/octet-stream',
            'content-disposition' => 'attachment; filename="geosite.dat"',
        ]);
        return $body;
    }
}
