<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe\convert;

use pocketmine\data\bedrock\BedrockDataFiles;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\ItemTypeDictionary;
use pocketmine\utils\Filesystem;
use pocketmine\utils\ProtocolSingletonTrait;
use function str_replace;

final class GlobalItemTypeDictionary{
	use ProtocolSingletonTrait;

	private const PATHS = [
		ProtocolInfo::CURRENT_PROTOCOL => "",
		ProtocolInfo::PROTOCOL_1_19_63 => "-1.19.63",
		ProtocolInfo::PROTOCOL_1_19_50 => "-1.19.50",
		ProtocolInfo::PROTOCOL_1_19_40 => "-1.19.40",
		ProtocolInfo::PROTOCOL_1_19_0 => "-1.19.0",
		ProtocolInfo::PROTOCOL_1_18_30 => "-1.18.30",
		ProtocolInfo::PROTOCOL_1_18_10 => "-1.18.10",
	];

	private static function make(int $protocolId) : self{
		$data = Filesystem::fileGetContents(str_replace(".json", self::PATHS[$protocolId] . ".json", BedrockDataFiles::REQUIRED_ITEM_LIST_JSON));
		$dictionary = ItemTypeDictionaryFromDataHelper::loadFromString($data);
		return new self($dictionary);
	}

	public function __construct(
		private ItemTypeDictionary $dictionary
	){}

	public function getDictionary() : ItemTypeDictionary{ return $this->dictionary; }

	public static function convertProtocol(int $protocolId) : int{
		return match ($protocolId) {
			ProtocolInfo::PROTOCOL_1_19_60 => ProtocolInfo::PROTOCOL_1_19_63,

			ProtocolInfo::PROTOCOL_1_19_30,
			ProtocolInfo::PROTOCOL_1_19_21,
			ProtocolInfo::PROTOCOL_1_19_20,
			ProtocolInfo::PROTOCOL_1_19_10 => ProtocolInfo::PROTOCOL_1_19_40,

			default => $protocolId
		};
	}
}
