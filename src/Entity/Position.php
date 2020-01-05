<?php

namespace App\Entity;

use App\BusinessRule\BusinessRule;
use App\BusinessRule\GreaterThanRule;
use App\BusinessRule\LessThanRule;
use App\Command\OpenPositionCommand;
use App\ValueObject\Direction;
use App\ValueObject\Level;
use App\ValueObject\Price;
use Webmozart\Assert\Assert;

final class Position
{
    const TYPE_BUY = 'buy';
    const TYPE_SELL = 'sell';

    const STATE_OPEN = 'open';
    const STATE_CLOSED_HALF = 'open';
    const STATE_HALF_BREAKEVEN = 'half.breakeven';
    const STATE_BREAKEVEN = 'breakeven';
    const STATE_CLOSED = 'closed';

    /** @var \DateTime */
    private $openTime;
    /** @var float */
    private $openPrice;
    /** @var Level */
    private $stop;
    /** @var Level */
    private $partialStop;
    /** @var Level */
    private $profit;
    /** @var Level */
    private $partialProfit;
    /** @var float */
    private $openLots;
    /** @var float */
    private $lots;
    /** @var string */
    private $instrument;
    /** @var int */
    private $ticket;
    /** @var int */
    private $digits;
    /** @var string */
    private $type;
    /** @var \DateTime */
    private $closedTime;
    /** @var \DateTime */
    private $closedHalfTime;
    /** @var string */
    private $currentState;
    /** @var int */
    private $magicNumber;

    private function __construct(
        string $type,
        int $magicNumber,
        int $ticket,
        \DateTime $openTime,
        float $openPrice,
        float $lots,
        float $digits,
        string $instrument,
        Level $stop,
        Level $partialStop,
        Level $profit,
        Level $partialProfit
    ) {
        $this->setType($type);
        $this->setOpenTime($openTime);
        $this->currentState = self::STATE_OPEN;
        $this->setOpenPrice($openPrice);
        $this->setLots($lots);
        $this->openLots = $lots;
        $this->setDigits($digits);
        $this->instrument = $instrument;
        $this->setTicket($ticket);
        $this->magicNumber = $magicNumber;
        $this->stop = $stop;
        $this->partialStop = $partialStop;
        $this->profit = $profit;
        $this->partialProfit = $partialProfit;

        $rules = $this->type === self::TYPE_BUY
            ? $this->getBuyRules()
            : $this->getSellRules();

        $this->validatePriceLevels($rules);
    }

    public static function BUY (
        int $magicNumber,
        int $ticket,
        \DateTime $openTime,
        float $openPrice,
        float $lots,
        float $digits,
        string $instrument,
        Level $stop,
        Level $partialStop,
        Level $profit,
        Level $partialProfit
    ) {
        return new Position(
            self::TYPE_BUY,
            $magicNumber,
            $ticket,
            $openTime,
            $openPrice,
            $lots,
            $digits,
            $instrument,
            $stop,
            $partialStop,
            $profit,
            $partialProfit
        );
    }

    public static function SELL(
        int $magicNumber,
        int $ticket,
        \DateTime $openTime,
        float $openPrice,
        float $lots,
        float $digits,
        string $instrument,
        Level $stop,
        Level $partialStop,
        Level $profit,
        Level $partialProfit
    ){
        return new Position(
            self::TYPE_SELL,
            $magicNumber,
            $ticket,
            $openTime,
            $openPrice,
            $lots,
            $digits,
            $instrument,
            $stop,
            $partialStop,
            $profit,
            $partialProfit
        );
    }

    public static function fromCommand(OpenPositionCommand $command, Level $stop, Level $partialStop, Level $profit, Level $partialProfit)
    {
        return new self(
            $command->getType(),
            $command->getMagicNumber(),
            $command->getTicket(),
            $command->getOpenTime(),
            $command->getOpenPrice(),
            $command->getLots(),
            $command->getDigits(),
            $command->getInstrument(),
            $stop,
            $partialStop,
            $profit,
            $partialProfit
        );
    }

    private function setOpenTime(\DateTime $openTime)
    {
        if ($openTime > new \DateTime('now')) {
            throw new \Exception('The open time cannot me higher than now');
        }

        $this->openTime = $openTime;
    }

    private function setType(string $type)
    {
        self::assertPositionType($type);
        $this->type = $type;
    }

    private function setLots(float $lots)
    {
        Assert::greaterThan($lots, 0, 'Lots should be greater than 0');
        $this->lots = $lots;
    }

    private function setTicket(int $ticket)
    {
        Assert::greaterThan($ticket, 0, 'Ticket should be greater than 0');
        $this->ticket = $ticket;
    }

    private function setDigits(int $digits)
    {
        Assert::greaterThan($digits, 0, 'Digits should be greater than 0');
        $this->digits = $digits;
    }

    public function setOpenPrice(float $openPrice)
    {
        Assert::greaterThan($openPrice, 0, 'Open price should be greater than 0');
        $this->openPrice = $openPrice;
    }

    public static function assertPositionType(string $type)
    {
        $validValues = self::getPositionTypes();
        Assert::true(
            in_array($type, $validValues),
            sprintf('Position type must be one of: %s', implode(',', $validValues))
        );
    }

    private function getBuyRules()
    {
        $openPriceLevel = new Level(new Price($this->openPrice), Direction::GREATER());

        return [
            // stop < partial_stop < open_price < partial_profit < profit
            new LessThanRule($this->stop, $this->partialStop, new \Exception('The stop must be less than the partial stop')),
            new LessThanRule($this->partialStop, $openPriceLevel, new \Exception('The partial stop must be less than the open price')),
            new LessThanRule($openPriceLevel, $this->partialProfit, new \Exception('The open price must be less than the partial profit')),
            new LessThanRule($this->partialProfit, $this->profit, new \Exception('The partial profit must be less than the profit')),
        ];
    }

    private function getSellRules()
    {
        $openPriceLevel = new Level(new Price($this->openPrice), Direction::LESS());

        return [
            //stop > partial_stop > open_price > partial_profit > profit
            new GreaterThanRule($this->stop, $this->partialStop, new \Exception('The stop must be greater than the partial stop')),
            new GreaterThanRule($this->partialStop, $openPriceLevel, new \Exception('The partial stop must be greater than the open price')),
            new GreaterThanRule($openPriceLevel, $this->partialProfit, new \Exception('The open price must be greater than the partial profit')),
            new GreaterThanRule($this->partialProfit, $this->profit, new \Exception('The partial profit must be greater than the profit')),
        ];
    }

    /**
     * @param BusinessRule[] $rules
     * @throws \Exception
     */
    private function validatePriceLevels(array $rules)
    {
        foreach ($rules as $rule) {
            $rule->validate();
        }
    }

    public function reachedPartialStop(float $price)
    {
        return $this->partialStop->hasReachedPrice() ?: $this->partialStop->reached($price);
    }

    public function reachedStop(float $price)
    {
        return $this->stop->hasReachedPrice() ?: $this->stop->reached($price);
    }

    public function reachedPartialProfit(float $price)
    {
        return $this->partialProfit->hasReachedPrice() ?: $this->partialProfit->reached($price);
    }

    public function reachedProfit(float $price)
    {
        return $this->profit->hasReachedPrice() ?: $this->profit->reached($price);
    }

    public function magicNumber()
    {
        return $this->magicNumber;
    }

    public function ticket()
    {
        return $this->ticket;
    }

    public static function getPositionTypes()
    {
        return array_filter((new \ReflectionClass(__CLASS__))->getConstants(), function ($value, $key) {
            return strpos($key, 'TYPE_') !== false;
        }, ARRAY_FILTER_USE_BOTH);
    }
}
