<?php

declare(strict_types=1);

namespace clary\wumpus\entity;

use clary\wumpus\Main;
use pocketmine\entity\Human;
use pocketmine\entity\Location;
use pocketmine\entity\Skin;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;

/**
 * NPC de Wumpus.
 *
 * IMPORTANTE - por que extiende Human y no una entidad propia:
 *
 * Registrar una entidad personalizada exige que el cliente conozca su
 * identificador Y tenga el resource pack aplicado. Si falla cualquiera de
 * las dos cosas, la entidad se vuelve INVISIBLE sin dar ningun error, que es
 * justo lo que pasaba antes.
 *
 * Un Human en cambio lleva su aspecto DENTRO de la piel: la piel de Bedrock
 * admite geometria propia ademas de la textura. Asi el modelo del Wumpus
 * viaja con la entidad en el propio paquete de spawn y se ve siempre, sin
 * depender de que el jugador acepte ningun pack. Es como funcionan los
 * plugins de NPC de PocketMine.
 */
class WumpusNpc extends Human
{
    /** @var array<string, int> jugador => momento del ultimo mensaje */
    private array $cooldowns = [];

    /**
     * Ajustes cacheados.
     *
     * ANTES se leian del config DENTRO de entityBaseTick, o sea 20 veces por
     * segundo y por NPC (y getNested hace explode + recorrido del array cada
     * vez). Con 5 NPCs eran 200 lecturas por segundo para unos valores que no
     * cambian nunca. Se leen una vez y se refrescan en refreshFromConfig().
     */
    private bool $lookEnabled = true;
    private float $lookRangeSq = 144.0;
    private int $cooldownSeconds = 5;
    private string $discordUrl = "";

    /**
     * Vector cero reutilizado.
     *
     * Se creaba un Vector3 nuevo en CADA tick solo para dejar la velocidad a
     * cero: 20 objetos por segundo y por NPC, todos tirados al instante.
     */
    private static ?Vector3 $zero = null;

    public static function createSkin(): Skin
    {
        $plugin = Main::getInstance();

        $texture = @file_get_contents($plugin->getDataFolder() . "wumpus_skin.bin");
        $geometry = @file_get_contents($plugin->getDataFolder() . "wumpus_geometry.json");

        if ($texture === false || $geometry === false) {
            // Sin los recursos no se puede construir la piel: se devuelve
            // una piel plana para que al menos no reviente el servidor.
            return new Skin("wumpus.fallback", str_repeat("\x00", 8192));
        }

        return new Skin(
            "clary.wumpus",
            $texture,
            "",
            "geometry.clary_wumpus",
            $geometry
        );
    }

    public function __construct(Location $location, ?CompoundTag $nbt = null)
    {
        parent::__construct($location, self::createSkin(), $nbt);
    }

    public function getName(): string
    {
        return "Wumpus";
    }

    protected function initEntity(CompoundTag $nbt): void
    {
        parent::initEntity($nbt);

        $this->setNoClientPredictions(true);   // el cliente no lo mueve solo
        $this->setHasGravity(false);
        $this->setNameTagAlwaysVisible(true);
        $this->setMaxHealth(20);
        $this->setHealth(20);

        $this->refreshFromConfig();
    }

    /** Aplica nombre y escala del config sin tener que recrear el NPC. */
    public function refreshFromConfig(): void
    {
        $name  = (string) Main::opt("npc.name");
        $line2 = (string) Main::opt("npc.name-line-2");

        $this->setNameTag($line2 !== "" ? $name . "\n" . $line2 : $name);
        $this->setScale((float) Main::opt("npc.scale"));

        // Cachear lo que se consulta en cada tick.
        $this->lookEnabled     = Main::opt("npc.look-at-players") === true;
        $range                 = (float) Main::opt("npc.look-range");
        $this->lookRangeSq     = $range * $range;
        $this->cooldownSeconds = (int) Main::opt("discord.cooldown");
        $this->discordUrl      = (string) Main::opt("discord.url");
    }

    /** Es un decorado: nada le hace daño. */
    public function attack(EntityDamageEvent $source): void
    {
        $source->cancel();
    }

    public function canBeMovedByCurrents(): bool
    {
        return false;
    }

    public function canBePushed(): bool
    {
        return false;
    }

    public function canCollideWith(\pocketmine\entity\Entity $entity): bool
    {
        return false;
    }

    /** Clic derecho / toque sobre el NPC. */
    public function onInteract(Player $player, Vector3 $clickPos): bool
    {
        $this->sendDiscord($player);
        return true;
    }

    /**
     * Envia el enlace con cooldown por jugador: al mantener pulsado en movil
     * si no se llenaria el chat de mensajes repetidos.
     */
    public function sendDiscord(Player $player): void
    {
        $name = $player->getName();
        $cooldown = $this->cooldownSeconds;
        $now = time();

        if ($cooldown > 0 && ($now - ($this->cooldowns[$name] ?? 0)) < $cooldown) {
            return;
        }
        $this->cooldowns[$name] = $now;

        $url = $this->discordUrl;

        $player->sendMessage(Main::prefix() . Main::opt("messages.interact"));
        $player->sendMessage("§9§n" . $url);
        $player->sendTitle("§9§lDISCORD", "§f" . $url, 5, 60, 10);
    }

    /** Se guarda con el mundo: sobrevive a los reinicios. */
    public function canSaveWithChunk(): bool
    {
        return true;
    }

    public function saveNBT(): CompoundTag
    {
        $nbt = parent::saveNBT();
        $nbt->setByte("WumpusNpc", 1);
        return $nbt;
    }

    /** Mira al jugador mas cercano y no se mueve del sitio. */
    public function entityBaseTick(int $tickDiff = 1): bool
    {
        // Solo tocar la velocidad si de verdad se ha movido (empuje de agua,
        // un pistón, otro plugin...). En reposo esto no hace nada.
        if ($this->motion->x !== 0.0 || $this->motion->y !== 0.0 || $this->motion->z !== 0.0) {
            $this->setMotion(self::$zero ??= new Vector3(0, 0, 0));
        }

        // La comprobacion de frecuencia va PRIMERO: antes se leia el config
        // en cada tick aunque luego se descartaran 9 de cada 10 pasadas.
        if ($this->lookEnabled && $this->ticksLived % 10 === 0) {
            $this->lookAtNearest();
        }

        return parent::entityBaseTick($tickDiff);
    }

    private function lookAtNearest(): void
    {
        $pos = $this->getPosition();
        $closest = null;
        $closestDist = $this->lookRangeSq;

        foreach ($this->getWorld()->getPlayers() as $player) {
            $d = $pos->distanceSquared($player->getPosition());
            if ($d < $closestDist) {
                $closest = $player;
                $closestDist = $d;
            }
        }

        if ($closest === null) {
            return;
        }

        $target = $closest->getPosition();
        $dx = $target->x - $pos->x;
        $dz = $target->z - $pos->z;
        $dy = $closest->getEyePos()->y - ($pos->y + $this->getEyeHeight());

        $yaw = rad2deg(atan2(-$dx, $dz));
        $pitch = rad2deg(-atan2($dy, sqrt($dx * $dx + $dz * $dz)));

        $this->setRotation($yaw, $pitch);
    }
}
