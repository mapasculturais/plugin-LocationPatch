<?php

namespace LocationPatch;

use DateTime;
use MapasCulturais\App;
use MapasCulturais\i;
use MapasCulturais\Entities\EntityRevision;
use MapasCulturais\Types\GeoPoint;

class Plugin extends \MapasCulturais\Plugin
{
    const METAKEY = "lastGeocodingAttempt";

    function __construct(array $config=[])
    {
        $config += [
            "enable" => false,
            "cutoff" => "19800101000001",
        ];
        parent::__construct($config);
        return;
    }

    public function _init()
    {
        if (!$this->config["enable"]) {
            return;
        }
        $app = App::i();
        $plugin = $this;
        $app->view->includeGeocodingAssets();
        $app->view->enqueueScript("app", "locationPatch", "js/locationPatch.js");
        $app->view->enqueueScript("app", "customizable", "js/customizable.js");
        $app->hook("entity(<<Agent|Space>>).save:before", function () {
            /** @var \MapasCulturais\Entity $this */
            if (($_SERVER["REQUEST_URI"] != "/agent/locationPatch/") &&
                ($_SERVER["REQUEST_URI"] != "/space/locationPatch/") &&
                $this->getMetadata(self::METAKEY)) {
                $this->setMetadata(self::METAKEY, "19800101000000");
            }
            return;
        });
        $app->hook("entity(EntityRevision).save:requests", function(&$requests) {
            /** @var \MapasCulturais\Entities\EntityRevision $this */
            if (!(($this->objectType == "MapasCulturais\Entities\Agent") &&
                  ($_SERVER["REQUEST_URI"] == "/agent/locationPatch/")) &&
                !(($this->objectType == "MapasCulturais\Entities\Space") &&
                  ($_SERVER["REQUEST_URI"] == "/space/locationPatch/"))) {
                return;
            }
            $this->action = EntityRevision::ACTION_AUTOUPDATED;
            return;
        });
        $app->hook("GET(<<agent|space>>.locationPatch)", function () use ($app, $plugin) {
            /** @var \MapasCulturais\Controller $this */
            $type = ["slug" => "agent", "class" => "Agent", "display" => "Agent"];
            if ($this instanceof \MapasCulturais\Controllers\Space) {
                $type = ["slug" => "space", "class" => "Space", "display" => "Space"];
            }
            $entity = null;
            if (isset($this->data["id"])) {
                $entity = $app->repo($type["class"])->find(intval($this->data["id"]));
                if ($entity && !$entity->canUser("edit")) {
                    $app->log->debug("The user is not allowed to call with this syntax.");
                    $this->errorJson(["message" => "User with ID {$app->user->id} cannot perform this operation."], 403);
                    return;
                }
            } else {
                $entity = self::selectEntity($type, $plugin->config["cutoff"]);
                if (!$entity) {
                    $app->log->debug("A suitable entity of type {$type["display"]} was not found.");
                    $this->json([]);
                    return;
                }
            }
            $token = uniqid();
            $meta = $entity->getMetadata();
            $fine = [];
            $coarse = [];
            if (isset($meta["En_Nome_Logradouro"])) {
                self::addIfNotNull($fine, ($meta["En_Num"] ?? null));
                $fine[] = $meta["En_Nome_Logradouro"];
            }
            self::addIfNotNull($coarse, ($meta["En_Bairro"] ?? null));
            $coarse[] = $meta["En_Municipio"];
            $coarse[] = $meta["En_Estado"];
            self::addIfNotNull($coarse, ($meta["En_Pais"] ?? null));
            $_SESSION["{$type["slug"]}-locationPatch-$token"] = [
                "id" => $entity->id,
                "timestamp" => (new DateTime())->format("YmdHis"),
                "address" => implode(", ", array_merge([implode(" ", array_reverse($fine))], $coarse))
            ];
            $elements = [
                "city" => $meta["En_Municipio"],
                "state" => $meta["En_Estado"]
            ];
            self::addIfNotNull($elements, ($meta["En_Nome_Logradouro"] ?? null), "streetName");
            self::addIfNotNull($elements, ($meta["En_Num"] ?? null), "number");
            self::addIfNotNull($elements, ($meta["En_Bairro"] ?? null), "neighborhood");
            self::addIfNotNull($elements, ($meta["En_CEP"] ?? null), "postalcode");
            self::addIfNotNull($elements, ($meta["En_Pais"] ?? null), "country");
            $this->json([
                "elements" => $elements,
                "query" => implode(", ", array_merge([implode(" ", $fine)], $coarse)),
                "fallback" => implode(", ", $coarse),
                "token" => $token
            ]);
            return;
        });
        $app->hook("POST(<<agent|space>>.locationPatch)", function() use ($app) {
            /** @var \MapasCulturais\Controller $this */
            $type = ["slug" => "agent", "class" => "Agent", "display" => "Agent"];
            if ($this instanceof \MapasCulturais\Controllers\Space) {
                $type = ["slug" => "space", "class" => "Space", "display" => "Space"];
            }
            $rToken = $this->postData["token"] ?? "";
            $sessionData = $_SESSION["{$type["slug"]}-locationPatch-$rToken"] ?? null;
            if (!$sessionData) {
                $this->errorJson(["message" => "Invalid token."], 400);
                return;
            }
            $entityID = $sessionData["id"] ?? 0;
            $entity = $app->repo($type["class"])->find($entityID);
            if (!$entity) {
                unset($_SESSION["{$type["slug"]}-locationPatch-$rToken"]);
                $this->errorJson(["message" => "{$type["display"]} not found."], 500);
                return;
            } else if (!isset($this->postData["latitude"]) ||
                       !isset($this->postData["longitude"])) {
                self::saveLocation($entity, $sessionData);
            } else {
                $loc = new GeoPoint(floatVal($this->postData["longitude"]),
                                    floatVal($this->postData["latitude"]));
                self::saveLocation($entity, $sessionData, $loc);
            }
            unset($_SESSION["{$type["slug"]}-locationPatch-$rToken"]);
            return;
        });
        return;
    }

    public function register()
    {
        if (!$this->config["enable"]) {
            return;
        }
        $this->registerAgentMetadata(self::METAKEY, [
            "label" => i::__("Data e hora da última tentativa de geocoding"),
            "type" => "string",
            "private" => true,
        ]);
        $this->registerSpaceMetadata(self::METAKEY, [
            "label" => i::__("Data e hora da última tentativa de geocoding"),
            "type" => "string",
            "private" => true,
        ]);
        return;
    }

    static function addIfNotNull(array &$list, $value, $key=null)
    {
        if (!empty($value)) {
            if (is_null($key)) {
                $list[] = $value;
            } else {
                $list[$key] = $value;
            }
        }
        return $list;
    }

    static function saveLocation($agent, $sessionData, $location=null)
    {
        $app = App::i();
        $app->disableAccessControl();
        $agent->setMetadata(self::METAKEY, $sessionData["timestamp"]);
        if ($location) {
            $agent->location = $location;
        }
        if (!isset($agent->getMetadata()["endereco"])) {
            $agent->setMetadata("endereco", $sessionData["address"]);
        }
        $agent->__skipQueuingPCacheRecreation = true;
        $agent->save(true);
        $app->enableAccessControl();
        return;
    }

    static function selectEntity(array $type, string $cutoff)
    {
        $app = App::i();
        $meta = self::METAKEY;
        $conn = $app->em->getConnection();
        $cache_id = "locationPatch.{$type["class"]}.count";
        if ($app->cache->contains($cache_id)) {
            $count = $app->cache->fetch($cache_id);
            if ($count > 1) {
                $app->cache->save($cache_id, ($count - 1), 300);
            }
        } else {
            $count = (int) $conn->fetchColumn("
                SELECT count(a.id) FROM {$type["slug"]} AS a WHERE EXISTS (
                    SELECT id FROM {$type["slug"]}_meta
                    WHERE key='En_Municipio' AND object_id=a.id
                ) AND EXISTS (
                    SELECT id FROM {$type["slug"]}_meta
                    WHERE key='En_Estado' AND object_id=a.id
                ) AND (
                    (
                        SELECT to_timestamp('$cutoff', 'YYYYMMDDHH24MISS')
                    ) > (
                        SELECT to_timestamp(
                            (
                                SELECT COALESCE(
                                    (
                                        SELECT value FROM {$type["slug"]}_meta
                                        WHERE object_id=a.id AND key='$meta'
                                    ), '19800101000000'
                                )
                            ), 'YYYYMMDDHH24MISS'
                        )
                    )
                )");
            if ($count > 100) {
                $app->cache->save($cache_id, $count, 600);
            }
        }
        $offset = max(0, rand(0, ($count - 1)));
        $entityid = $conn->fetchColumn("
            SELECT a.id FROM {$type["slug"]} AS a WHERE EXISTS (
                SELECT id FROM {$type["slug"]}_meta
                WHERE key='En_Municipio' AND object_id=a.id
            ) AND EXISTS (
                SELECT id FROM {$type["slug"]}_meta
                WHERE key='En_Estado' AND object_id=a.id
            ) AND (
                (
                    SELECT to_timestamp('$cutoff', 'YYYYMMDDHH24MISS')
                ) > (
                    SELECT to_timestamp(
                        (
                            SELECT COALESCE(
                                (
                                    SELECT value FROM {$type["slug"]}_meta
                                    WHERE object_id=a.id AND key='$meta'
                                ), '19800101000000'
                            )
                        ), 'YYYYMMDDHH24MISS'
                    )
                )
            ) LIMIT 1 OFFSET {$offset}");
        return $app->repo($type["class"])->find($entityid);
    }
}
