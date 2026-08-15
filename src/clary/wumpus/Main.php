<?php

declare(strict_types=1);

namespace clary\wumpus;

use clary\wumpus\command\WumpusCommand;
use clary\wumpus\entity\WumpusNpc;
use pocketmine\entity\EntityDataHelper;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\Listener;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\world\World;

class Main extends PluginBase implements Listener
{
    private static Main $instance;
    private static Config $config;

    /** Valores de respaldo, identicos a los del config.yml de fabrica. */
    private const DEFAULTS = [
        "discord.url"            => "https://discord.gg/tuservidor",
        "discord.cooldown"       => 5,
        "npc.name"               => "§9§lDISCORD",
        "npc.name-line-2"        => "§7Toca para entrar",
        "npc.scale"              => 1.0,
        "npc.look-at-players"    => true,
        "npc.look-range"         => 12.0,
        "messages.prefix"        => "§8[§9Discord§8] §r",
        "messages.interact"      => "§bEntra a nuestro Discord:",
        "messages.spawned"       => "§aNPC de Discord creado.",
        "messages.removed"       => "§aNPC eliminado.",
        "messages.removed-all"   => "§aEliminados §e{count} §aNPCs.",
        "messages.none-near"     => "§cNo hay ningun NPC cerca.",
        "messages.players-only"  => "§cSolo en el juego.",
        "messages.cooldown"      => "§7Espera un momento.",
    ];

    public static function getInstance(): Main
    {
        return self::$instance;
    }

    public static function cfg(): Config
    {
        return self::$config;
    }

    /** Lee una opcion cayendo siempre al valor de fabrica correcto. */
    public static function opt(string $path): mixed
    {
        return self::$config->getNested($path, self::DEFAULTS[$path] ?? null);
    }

    public static function prefix(): string
    {
        return (string) self::opt("messages.prefix");
    }

    protected function onEnable(): void
    {
        self::$instance = $this;
        $this->saveResource("config.yml");
        self::$config = new Config($this->getDataFolder() . "config.yml", Config::YAML);

        // Recursos de la piel: la textura en RGBA crudo y la geometria.
        // Van dentro del plugin y se extraen a su carpeta de datos, porque
        // el modelo viaja DENTRO de la piel de la entidad (ver WumpusNpc).
        $this->saveResource("wumpus_skin.bin", true);
        $this->saveResource("wumpus_geometry.json", true);

        EntityFactory::getInstance()->register(
            WumpusNpc::class,
            fn(World $world, CompoundTag $nbt): WumpusNpc =>
                new WumpusNpc(EntityDataHelper::parseLocation($nbt, $world), $nbt),
            ["WumpusNpc"]
        );

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getServer()->getCommandMap()->register("wumpus", new WumpusCommand($this));
    }

    /**
     * Coloca el NPC justo debajo del jugador, mirando hacia donde el mira.
     */
    public function spawnNpc(Player $player): WumpusNpc
    {
        $pos = $player->getPosition();

        // "Justo debajo": a los pies del jugador, no dentro de su cuerpo.
        $location = Location::fromObject(
            new Vector3($pos->x, $pos->y, $pos->z),
            $player->getWorld(),
            // Mira hacia el jugador: se le da la vuelta a su yaw.
            fmod($player->getLocation()->getYaw() + 180.0, 360.0),
            0.0
        );

        $npc = new WumpusNpc($location, new CompoundTag());
        $npc->spawnToAll();
        return $npc;
    }

    /**
     * @return WumpusNpc[]
     */
    public function getNpcsNear(Player $player, float $radius): array
    {
        $out = [];
        $pos = $player->getPosition();

        foreach ($player->getWorld()->getEntities() as $entity) {
            if ($entity instanceof WumpusNpc
                && $pos->distanceSquared($entity->getPosition()) <= $radius ** 2) {
                $out[] = $entity;
            }
        }
        return $out;
    }

    /**
     * Golpear el NPC tambien manda el Discord.
     *
     * En movil, el toque llega como golpe y no como interaccion, asi que sin
     * esto los jugadores de movil no recibirian nada al tocarlo.
     *
     * @priority MONITOR
     */
    public function onDamage(EntityDamageByEntityEvent $event): void
    {
        $entity = $event->getEntity();
        if (!$entity instanceof WumpusNpc) {
            return;
        }

        $event->cancel();

        $damager = $event->getDamager();
        if ($damager instanceof Player) {
            $entity->sendDiscord($damager);
        }
    }

    public function reloadConfiguration(): void
    {
        self::$config = new Config($this->getDataFolder() . "config.yml", Config::YAML);

        // Refrescar los NPC ya colocados sin tener que recrearlos.
        foreach ($this->getServer()->getWorldManager()->getWorlds() as $world) {
            foreach ($world->getEntities() as $entity) {
                if ($entity instanceof WumpusNpc) {
                    $entity->refreshFromConfig();
                }
            }
        }
    }
}
