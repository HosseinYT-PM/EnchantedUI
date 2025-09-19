<?php

namespace ItsRealNise\EnchantedUI;

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

    public $eco;
    public $piggyCE;
    public $usePiggyCE;

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
        $this->eco = EconomyAPI::getInstance();

        // Check if PiggyCustomEnchants should be used
        $this->usePiggyCE = $this->shop->getNested('piggycustomenchants', false);

        // Load PiggyCustomEnchants if enabled in config and available
        if ($this->usePiggyCE) {
            $this->piggyCE = $this->getServer()->getPluginManager()->getPlugin("PiggyCustomEnchants");
            if ($this->piggyCE !== null) {
                $this->getLogger()->info("PiggyCustomEnchants support enabled!");
            } else {
                $this->getLogger()->warning("PiggyCustomEnchants is enabled in config but not installed!");
                $this->usePiggyCE = false;
            }
        }
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
            $shop['piggycustomenchants'] = false; // Add default value
            $shop['messages']['incompatible-enchantment'] = '';
            foreach ($shop['shop'] as $list => $data) {
                $data['incompatible-enchantments'] = array();
                $shop['shop'][$list] = $data;
            }
            // Initialize custom enchantments shop if not exists
            if (!isset($shop['customenchantsshop'])) {
                $shop['customenchantsshop'] = [];
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

    public function listForm(Player $player): void
    {
        $shopData = $this->shop->getNested('shop', []);
        $customShopData = $this->usePiggyCE ? $this->shop->getNested('customenchantsshop', []) : [];

        // اضافه کردن کلید 'custom' برای تشخیص آیتم‌های سفارشی
        foreach ($customShopData as $key => $item) {
            $customShopData[$key]['custom'] = true;
        }
        foreach ($shopData as $key => $item) {
            $shopData[$key]['custom'] = false;
        }

        $allItems = array_merge($shopData, $customShopData);

        $form = new SimpleForm(function (Player $player, $data = null) use ($allItems) {
            if ($data === null) {
                $this->sendNote($player, $this->shop->getNested('messages.thanks'));
                return;
            }

            $selectedItem = $allItems[$data] ?? null;
            if ($selectedItem === null) return;

            if ($selectedItem['custom']) {
                $this->buyCustomForm($player, $data);
            } else {
                $this->buyForm($player, $data);
            }
        });

        $form->setTitle($this->shop->getNested('Title'));

        // اضافه کردن دکمه‌ها
        foreach ($allItems as $item) {
            $var = [
                "NAME" => $item['name'],
                "PRICE" => $item['price']
            ];
            $buttonText = $item['custom'] ? "§6[CUSTOM] " . $this->replace($this->shop->getNested('Button'), $var)
                : $this->replace($this->shop->getNested('Button'), $var);

            $form->addButton($buttonText);
        }

        $player->sendForm($form);
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
        if (!isset($array[$id])) {
            $player->sendMessage("§cEnchantment not found!");
            return;
        }

        $form = new CustomForm(function (Player $player, $data = null) use ($array, $id) {
            if ($data === null) {
                return false;
            }

            $incompatible = $this->isCompatible($player, $array[$id]['incompatible-enchantments']);
            $var = array(
                "NAME" => $array[$id]['name'],
                "PRICE" => $array[$id]['price'] * $data[1],
                "LEVEL" => $data[1],
                "MONEY" => $this->eco->myMoney($player),
                "INCOMPATIBLE" => $incompatible
            );

            if (!$player->getInventory()->getItemInHand() instanceof Tool && !$player->getInventory()->getItemInHand() instanceof Armor) {
                $this->sendNote($player, $this->shop->getNested('messages.hold-item'), $var);
                return false;
            }

            if ($incompatible !== false) {
                $this->sendNote($player, $this->shop->getNested('messages.incompatible-enchantment'), $var);
                return false;
            }

            if ($data[1] > $array[$id]['max-level'] || $data[1] < 1) {
                $player->sendMessage("§cInvalid enchantment level!");
                return false;
            }

            $cost = $array[$id]['price'] * $data[1];
            if ($this->eco->myMoney($player) >= $cost) {
                $this->eco->reduceMoney($player, $cost);
                $this->enchantItem($player, $data[1], $array[$id]['enchantment']);
                $this->sendNote($player, $this->shop->getNested('messages.paid-success'), $var);
            } else {
                $this->sendNote($player, $this->shop->getNested('messages.not-enough-money'), $var);
            }
            return false;
        });

        $form->setTitle($this->shop->getNested('Title'));
        $form->addLabel($this->replace($this->shop->getNested('messages.label'), ["PRICE" => $array[$id]['price']]));
        $form->addSlider($this->shop->getNested('slider-title'), 1, $array[$id]['max-level'], 1, 1);
        $player->sendForm($form);
    }

    /**
     * @param Player $player
     * @param int $id
     */
    public function buyCustomForm(Player $player, int $id): void
    {
        if (!$this->usePiggyCE) {
            $player->sendMessage("§cCustom enchantments are disabled!");
            return;
        }

        $array = $this->shop->getNested('customenchantsshop');
        if (!isset($array[$id])) {
            $player->sendMessage("§cCustom enchantment not found!");
            return;
        }

        $form = new CustomForm(function (Player $player, $data = null) use ($array, $id) {
            if ($data === null) {
                return false;
            }

            $incompatible = $this->isCustomCompatible($player, $array[$id]['incompatible-enchantments']);
            $var = array(
                "NAME" => $array[$id]['name'],
                "PRICE" => $array[$id]['price'] * $data[1],
                "LEVEL" => $data[1],
                "MONEY" => $this->eco->myMoney($player),
                "INCOMPATIBLE" => $incompatible
            );

            if (!$player->getInventory()->getItemInHand() instanceof Tool && !$player->getInventory()->getItemInHand() instanceof Armor) {
                $this->sendNote($player, $this->shop->getNested('messages.hold-item'), $var);
                return false;
            }

            if ($incompatible !== false) {
                $this->sendNote($player, $this->shop->getNested('messages.incompatible-enchantment'), $var);
                return false;
            }

            if ($data[1] > $array[$id]['max-level'] || $data[1] < 1) {
                $player->sendMessage("§cInvalid enchantment level!");
                return false;
            }

            $cost = $array[$id]['price'] * $data[1];
            if ($this->eco->myMoney($player) >= $cost) {
                $this->eco->reduceMoney($player, $cost);
                $this->enchantItem($player, $data[1], $array[$id]['enchantment']);
                $this->sendNote($player, $this->shop->getNested('messages.paid-success'), $var);
            } else {
                $this->sendNote($player, $this->shop->getNested('messages.not-enough-money'), $var);
            }
            return false;
        });

        $form->setTitle("§6Custom " . $this->shop->getNested('Title'));
        $form->addLabel($this->replace($this->shop->getNested('messages.label'), ["PRICE" => $array[$id]['price']]));
        $form->addSlider($this->shop->getNested('slider-title'), 1, $array[$id]['max-level'], 1, 1);
        $player->sendForm($form);
    }

    /**
     * @param Player $player
     * @param array $incompatibleEnchantments
     *
     * @return int|false
     */
    public function isCompatible(Player $player, array $incompatibleEnchantments)
    {
        $item = $player->getInventory()->getItemInHand();

        if (empty($incompatibleEnchantments)) {
            return false;
        }

        foreach ($item->getEnchantments() as $enchantmentInstance) {
            $enchantmentId = EnchantmentIdMap::getInstance()->toId($enchantmentInstance->getType());

            if (in_array($enchantmentId, $incompatibleEnchantments)) {
                return $enchantmentId;
            }
        }

        return false;
    }

    /**
     * Check compatibility for custom enchantments
     */
    public function isCustomCompatible(Player $player, array $incompatibleEnchantments): bool
    {
        // For custom enchantments, we'll need a different approach
        // This is a placeholder - you might need to implement custom compatibility checking
        return false;
    }

    /**
     * @param Player $player
     * @param int $level
     * @param int|string $enchantment
     */
    public function enchantItem(Player $player, int $level, $enchantment): void
    {
        $item = $player->getInventory()->getItemInHand();

        // Handle PiggyCustomEnchants if available and enchantment is a string
        if ($this->usePiggyCE && $this->piggyCE !== null && is_string($enchantment)) {
            $this->applyCustomEnchantment($player, $item, $level, $enchantment);
            return;
        }

        // Handle vanilla enchantments
        $this->applyVanillaEnchantment($player, $item, $level, $enchantment);
    }

    /**
     * Apply vanilla Minecraft enchantment
     */
    private function applyVanillaEnchantment(Player $player, $item, int $level, $enchantment): void
    {
        $enchantmentId = is_numeric($enchantment) ? (int)$enchantment : $enchantment;
        $ench = EnchantmentIdMap::getInstance()->fromId($enchantmentId);

        if ($ench !== null) {
            $enchantmentInstance = new EnchantmentInstance($ench, $level);
            $item->addEnchantment($enchantmentInstance);
            $player->getInventory()->setItemInHand($item);
        } else {
            $player->sendMessage("§cFailed to apply enchantment: Invalid enchantment ID");
        }
    }

    /**
     * Apply PiggyCustomEnchants enchantment
     */
    private function applyCustomEnchantment(Player $player, $item, int $level, string $enchantmentName): void
    {
        try {
            $reflectionClass = new \ReflectionClass($this->piggyCE);

            if ($reflectionClass->hasMethod('addEnchantment')) {
                $method = $reflectionClass->getMethod('addEnchantment');
                $method->invoke($this->piggyCE, $item, $enchantmentName, $level);
                $player->getInventory()->setItemInHand($item);
                return;
            }

            $player->sendMessage("§cFailed to apply custom enchantment");

        } catch (\ReflectionException $e) {
            $player->sendMessage("§cError applying custom enchantment");
        }
    }
}
