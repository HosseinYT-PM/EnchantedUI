<?php

namespace ItsRealNise\EnchantedUI;

use DaPigGuy\PiggyCustomEnchants\CustomEnchants\CustomEnchants;
use jojoe77777\FormAPI\{CustomForm, SimpleForm};
use onebone\economyapi\EconomyAPI;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\data\bedrock\EnchantmentIdMap;
use pocketmine\item\{Armor, enchantment\EnchantmentInstance, Tool};
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;

/**
 * Class Main
 * @package ItsRealNise\EnchantUI
 */
class Main extends PluginBase
{

    /** @var Config $shop */
    public $shop;

    public $piggyCE;
    public $eco;

    public function onEnable(): void
    {
        if (is_null($this->getServer()->getPluginManager()->getPlugin("EconomyAPI"))) {
            $this->getLogger()->error("in order to use EnchantUI you need to install EconomyAPI.");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }
        @mkdir($this->getDataFolder());
        $this->shop = new Config($this->getDataFolder() . "Shop.yml", Config::YAML);
        $this->UpdateConfig();
        $this->saveDefaultConfig();
        $this->getServer()->getPluginManager()->registerEvents(new EventListener($this), $this);
        $this->piggyCE = $this->getServer()->getPluginManager()->getPlugin("PiggyCustomEnchants");
        $this->eco = $this->getServer()->getPluginManager()->getPlugin("EconomyAPI");
    }

    public function UpdateConfig(): void
    {
        if (is_null($this->shop->getNested('version'))) {
            file_put_contents($this->getDataFolder() . "Shop.yml", $this->getResource("Shop.yml"));
            $this->shop->reload();
            $this->getLogger()->notice("plugin config has been updated");
            return;
        }
        if ($this->shop->getNested('version') != '0.5') {
            $shop = $this->shop->getAll();
            $shop['version'] = '0.5';
            $shop['enchanting-table'] = true;
            $shop['messages']['incompatible-enchantment'] = '';
            foreach ($shop['shop'] as $list => $data) {
                $data['incompatible-enchantments'] = array();
                $shop['shop'][$list] = $data;
            }
            $this->shop->setAll($shop);
            $this->shop->save();
            $this->shop->reload();
            $this->getLogger()->notice("Plugin config has been updated");
            return;
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool
    {
        if ($command->getName() === "eshop") {
            if (!$sender->hasPermission("eshop.command")) {
                $sender->sendMessage($this->shop->getNested('messages.no-perm', "§cYou don't have permission."));
                return true;
            }
            if (!$sender instanceof Player) {
                $sender->sendMessage("§cPlease use this command in-game.");
                return true;
            }
            $this->listForm($sender);
        }
        return false;
    }

    public function listForm(Player $player): SimpleForm
    {
        $form = new SimpleForm(function (Player $player, $data = null) {
            if ($data === null) {
                $this->sendNote($player, $this->shop->getNested('messages.thanks'));
                return false;
            }
            $this->buyForm($player, $data);
            return false;
        });
        foreach ($this->shop->getNested('shop') as $name) {
            $var = array(
                "NAME" => $name['name'],
                "PRICE" => $name['price']
            );
            $form->addButton($this->replace($this->shop->getNested('Button'), $var));
        }
        $form->setTitle($this->shop->getNested('Title'));
        $player->sendForm($form);
        return $form;
    }

    /**
     * @param Player $player
     * @param null|mixed|string $msg
     * @param array $var
     */
    public function sendNote(Player $player, $msg, array $var = []): void
    {
        if (!is_null($msg)) $player->sendMessage($this->replace($msg, $var));
    }

    /**
     * @param string $message
     * @param array $keys
     *
     * @return string
     */
    public function replace(string $message, array $keys): string
    {
        foreach ($keys as $word => $value) {
            $message = str_replace("{" . $word . "}", $value, $message);
        }
        return $message;
    }

    /**
     * @param Player $player
     * @param int $id
     */
    public function buyForm(Player $player, int $id): void
    {
        $array = $this->shop->getNested('shop');
        $form = new CustomForm(function (Player $player, $data = null) use ($array, $id) {
            if ($data === null) {
                return false;
            }
            $var = array(
                "NAME" => $array[$id]['name'],
                "PRICE" => $array[$id]['price'] * $data[1],
                "LEVEL" => $data[1],
                "MONEY" => $this->eco->myMoney($player),
                "INCOMPATIBLE" => $incompatible = $this->isCompatible($player, $array[$id]['incompatible-enchantments'])
            );
            if ($data == null) {
                $this->listForm($player);
                return false;
            }
            if (!$player->getInventory()->getItemInHand() instanceof Tool and !$player->getInventory()->getItemInHand() instanceof Armor) {
                $this->sendNote($player, $this->shop->getNested('messages.hold-item'), $var);
                return false;
            }
            if (!is_null($incompatible)) {
                $this->sendNote($player, $this->shop->getNested('messages.incompatible-enchantment'), $var);
                return false;
            }
            if ($data[1] > $array[$id]['max-level'] or $data[1] < 1) {
                return false;
            }
            if ($this->eco->myMoney($player) > $c = $array[$id]['price'] * $data[1]) {
                $this->eco->reduceMoney($player, $c);
                $this->enchantItem($player, $data[1], $array[$id]['enchantment']);
                $this->sendNote($player, $this->shop->getNested('messages.paid-success'), $var);
            } else {
                $this->sendNote($player, $this->shop->getNested('messages.not-enough-money'), $var);
            }
            return false;
        }
        );
        $form->addLabel($this->replace($this->shop->getNested('messages.label'), ["PRICE" => $array[$id]['price']]));
        $form->setTitle($this->shop->getNested('Title'));
        $form->addSlider($this->shop->getNested('slider-title'), 1, $array[$id]['max-level'], 1, -1);
        $player->sendForm($form);
    }

    /**
     * @param Player $player
     * @param array $array
     *
     * @return int|mixed|null
     */
    public function isCompatible(Player $player, array $array)
    {
        $item = $player->getInventory()->getItemInHand();
        //TODO: the ability to use strings
        foreach ($array as $enchantment) {
            if ($item->hasEnchantment($enchantment)) {
                $id = $enchantment;
                return $id;
            }
        }
        return false;
    }

    /**
     * @param Player $player
     * @param int $level
     * @param int|String $enchantment
     */
    public function enchantItem(Player $player, int $level, $enchantment): void
    {
        $item = $player->getInventory()->getItemInHand();
        if (is_string($enchantment)) {
            $ench = EnchantmentIdMap::getInstance()->fromId((string)$enchantment);
            if ($this->piggyCE !== null && $ench === null) {
                $ench = CustomEnchants::getEnchantmentByName((string)$enchantment);
            }
            if ($this->piggyCE !== null && $ench instanceof CustomEnchants) {
                $this->piggyCE->addEnchantment($item, $ench->getName(), (int)$level);
            } else {
                $item->addEnchantment(new EnchantmentInstance($ench, (int)$level));
            }
        }
        if (is_int($enchantment)) {
            $ench = EnchantmentIdMap::getInstance()->fromId($enchantment);
            $item->addEnchantment(new EnchantmentInstance($ench, (int)$level));
        }
        $player->getInventory()->setItemInHand($item);
    }
}

