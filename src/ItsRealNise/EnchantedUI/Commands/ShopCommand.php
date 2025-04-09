<?php
namespace ItsRealNise\EnchantedUI\Commands;

use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\command\{
    Command,
    PluginCommand,
    CommandSender
};
use pocketmine\plugin\PluginOwned;
use ItsRealNise\EnchantedUI\Main;

/**
 * Class ShopCommand
 * @package ItsRealNise\EnchantUI\Commands
 */
class ShopCommand extends Command implements PluginOwned {

    private Main $plugin;

    /**
    * ShopCommand constructor.
    * @param Main $plugin
    */
    public function __construct(Main $plugin){
        parent::__construct("eshop", "Enchant Shop commands", \null, ["eshop"]);
        $this->plugin = $plugin;
    }
    
   /**
    * @param CommandSender $sender
    * @param string $commandLabel
    * @param array $args
    *
    * @return bool
    */
    public function execute(CommandSender $sender, string $commandLabel, array $args): bool{
        if(!$sender->hasPermission("eshop.command")){
            $sender->sendMessage($this->plugin->shop->getNested('messages.no-perm'));
            return true;
        }
        /**if(!$sender instanceof Player){
            $sender->sendMessage("Please use this in-game.");
            return true;
        }*/
        if ($sender instanceof Player) {
            $this->plugin->listForm($sender);
        }
        return true;
	}
   
   public function getOwningPlugin(): Plugin {
		return $this->plugin;
	}
}
