<?php

declare(strict_types=1);

namespace clary\wumpus\command;

use clary\wumpus\Main;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\PluginOwned;

class WumpusCommand extends Command implements PluginOwned
{
    /** Radio de busqueda al eliminar el NPC mas cercano. */
    private const REMOVE_RADIUS = 6.0;

    private Main $plugin;

    public function __construct(Main $plugin)
    {
        parent::__construct("wumpus", "Coloca el NPC de Discord", "/wumpus", ["discordnpc", "npcdiscord"]);
        $this->setPermission("wumpusnpc.command");
        $this->plugin = $plugin;
    }

    public function getOwningPlugin(): Main
    {
        return $this->plugin;
    }

    public function execute(CommandSender $sender, string $label, array $args): bool
    {
        if (!$this->testPermission($sender)) {
            return true;
        }

        $sub = strtolower($args[0] ?? "spawn");

        switch ($sub) {
            case "spawn":
            case "poner":
                if (!$sender instanceof Player) {
                    $sender->sendMessage(Main::prefix() . Main::opt("messages.players-only"));
                    return true;
                }
                $this->plugin->spawnNpc($sender);
                $sender->sendMessage(Main::prefix() . Main::opt("messages.spawned"));
                return true;

            case "remove":
            case "quitar":
                if (!$sender instanceof Player) {
                    $sender->sendMessage(Main::prefix() . Main::opt("messages.players-only"));
                    return true;
                }
                $near = $this->plugin->getNpcsNear($sender, self::REMOVE_RADIUS);
                if (count($near) === 0) {
                    $sender->sendMessage(Main::prefix() . Main::opt("messages.none-near"));
                    return true;
                }
                $near[0]->flagForDespawn();
                $sender->sendMessage(Main::prefix() . Main::opt("messages.removed"));
                return true;

            case "removeall":
            case "quitartodos":
                $count = 0;
                foreach ($this->plugin->getServer()->getWorldManager()->getWorlds() as $world) {
                    foreach ($world->getEntities() as $entity) {
                        if ($entity instanceof \clary\wumpus\entity\WumpusNpc) {
                            $entity->flagForDespawn();
                            $count++;
                        }
                    }
                }
                $sender->sendMessage(Main::prefix() . str_replace(
                    "{count}", (string) $count,
                    (string) Main::opt("messages.removed-all")
                ));
                return true;

            case "url":
            case "link":
                if (!isset($args[1])) {
                    $sender->sendMessage(Main::prefix() . "§7Enlace actual: §9" . Main::opt("discord.url"));
                    $sender->sendMessage("§7Cambialo con §e/wumpus url <enlace>");
                    return true;
                }
                Main::cfg()->setNested("discord.url", $args[1]);
                Main::cfg()->save();
                $sender->sendMessage(Main::prefix() . "§aEnlace actualizado a §9" . $args[1]);
                return true;

            case "reload":
            case "recargar":
                $this->plugin->reloadConfiguration();
                $sender->sendMessage(Main::prefix() . "§aConfiguracion recargada.");
                return true;

            default:
                $sender->sendMessage("§8§m--------------------------------");
                $sender->sendMessage("§9§lWumpus NPC §r§7- comandos");
                $sender->sendMessage("§7 - §e/wumpus§7: colocar el NPC donde estas");
                $sender->sendMessage("§7 - §e/wumpus remove§7: quitar el NPC mas cercano");
                $sender->sendMessage("§7 - §e/wumpus removeall§7: quitar todos");
                $sender->sendMessage("§7 - §e/wumpus url <enlace>§7: cambiar el Discord");
                $sender->sendMessage("§7 - §e/wumpus reload§7: recargar configuracion");
                $sender->sendMessage("§8§m--------------------------------");
                return true;
        }
    }
}
