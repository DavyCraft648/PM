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

namespace pocketmine\entity\object;

use pocketmine\block\Block;
use pocketmine\block\BlockFactory;
use pocketmine\block\utils\Fallable;
use pocketmine\block\Water;
use pocketmine\data\bedrock\block\BlockStateDeserializeException;
use pocketmine\data\SavedDataLoadingException;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityBlockChangeEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\player\Player;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use function abs;

class FallingBlock extends Entity{
	private const TAG_FALLING_BLOCK = "FallingBlock"; //TAG_Compound

	public static function getNetworkTypeId() : string{ return EntityIds::FALLING_BLOCK; }

	protected Block $block;

	public function __construct(Location $location, Block $block, ?CompoundTag $nbt = null){
		$this->block = $block;
		parent::__construct($location, $nbt);
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.98, 0.98); }

	protected function getInitialDragMultiplier() : float{ return 0.02; }

	protected function getInitialGravity() : float{ return 0.04; }

	public static function parseBlockNBT(BlockFactory $factory, CompoundTag $nbt) : Block{

		//TODO: 1.8+ save format
		$blockDataUpgrader = GlobalBlockStateHandlers::getUpgrader();
		if(($fallingBlockTag = $nbt->getCompoundTag(self::TAG_FALLING_BLOCK)) !== null){
			$blockStateData = $blockDataUpgrader->upgradeBlockStateNbt($fallingBlockTag);
		}else{
			if(($tileIdTag = $nbt->getTag("TileID")) instanceof IntTag){
				$blockId = $tileIdTag->getValue();
			}elseif(($tileTag = $nbt->getTag("Tile")) instanceof ByteTag){
				$blockId = $tileTag->getValue();
			}else{
				throw new SavedDataLoadingException("Missing legacy falling block info");
			}
			$damage = $nbt->getByte("Data", 0);

			$blockStateData = $blockDataUpgrader->upgradeIntIdMeta($blockId, $damage);
		}
		if($blockStateData === null){
			throw new SavedDataLoadingException("Invalid legacy falling block");
		}

		try{
			$blockStateId = GlobalBlockStateHandlers::getDeserializer()->deserialize($blockStateData);
		}catch(BlockStateDeserializeException $e){
			throw new SavedDataLoadingException($e->getMessage(), 0, $e);
		}

		return $factory->fromStateId($blockStateId);
	}

	public function canCollideWith(Entity $entity) : bool{
		return false;
	}

	public function canBeMovedByCurrents() : bool{
		return false;
	}

	public function attack(EntityDamageEvent $source) : void{
		if($source->getCause() === EntityDamageEvent::CAUSE_VOID){
			parent::attack($source);
		}
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		if($this->closed){
			return false;
		}

		$hasUpdate = parent::entityBaseTick($tickDiff);

		if(!$this->isFlaggedForDespawn()){
			$world = $this->getWorld();
			$pos = $this->location->add(-$this->size->getWidth() / 2, $this->size->getHeight(), -$this->size->getWidth() / 2)->floor();

			$this->block->position($world, $pos->x, $pos->y, $pos->z);

			$blockTarget = null;
			if($this->block instanceof Fallable){
				$blockTarget = $this->block->tickFalling();
			}

			if($this->onGround || $blockTarget !== null){
				$this->flagForDespawn();

				$block = $world->getBlock($pos);
				if(!$block->canBeReplaced() || !$world->isInWorld($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ()) || ($this->onGround && abs($this->location->y - $this->location->getFloorY()) > 0.001)){
					//FIXME: anvils are supposed to destroy torches
					$world->dropItem($this->location, $this->block->asItem());
				}else{
					$ev = new EntityBlockChangeEvent($this, $block, $blockTarget ?? $this->block);
					$ev->call();
					if(!$ev->isCancelled()){
						$to = $ev->getTo();
						$world->setBlock($pos, $to);
						if($block instanceof Water && $to->canWaterlogged($block)){
							$world->setBlockLayer($pos, $block, 1);
						}
					}
				}
				$hasUpdate = true;
			}
		}

		return $hasUpdate;
	}

	public function getBlock() : Block{
		return $this->block;
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setTag(self::TAG_FALLING_BLOCK, GlobalBlockStateHandlers::getSerializer()->serialize($this->block->getStateId())->toNbt());

		return $nbt;
	}

	protected function sendSpawnPacket(Player $player) : void{
		$this->getNetworkProperties()->setInt(EntityMetadataProperties::VARIANT, RuntimeBlockMapping::getInstance()->toRuntimeId($this->block->getStateId(), RuntimeBlockMapping::getMappingProtocol($player->getNetworkSession()->getProtocolId())));
		$this->getNetworkProperties()->clearDirtyProperties(); //needed for multi protocol

		parent::sendSpawnPacket($player);
	}

	//protected function syncNetworkData(EntityMetadataCollection $properties) : void{ No need due to multi protocol
	//	parent::syncNetworkData($properties);
	//
	//	$properties->setInt(EntityMetadataProperties::VARIANT, RuntimeBlockMapping::getInstance()->toRuntimeId($this->block->getStateId(), RuntimeBlockMapping::getMappingProtocol($player->getNetworkSession()->getProtocolId())));
	//}

	public function getOffsetPosition(Vector3 $vector3) : Vector3{
		return $vector3->add(0, 0.49, 0); //TODO: check if height affects this
	}
}
