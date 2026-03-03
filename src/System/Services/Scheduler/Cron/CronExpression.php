<?php
declare(strict_types=1);

namespace System\Services\Scheduler\Cron;

final class CronExpression
{
    /** @var array<int,bool> */
    private array $m;
    /** @var array<int,bool> */
    private array $h;
    /** @var array<int,bool> */
    private array $dom;
    /** @var array<int,bool> */
    private array $mon;
    /** @var array<int,bool> */
    private array $dow;

    private function __construct(array $m, array $h, array $dom, array $mon, array $dow)
    {
        $this->m=$m; $this->h=$h; $this->dom=$dom; $this->mon=$mon; $this->dow=$dow;
    }

    public static function parse(string $expr): self
    {
        $p = preg_split('/\s+/', trim($expr));
        if (!$p || count($p) !== 5) throw new \InvalidArgumentException("Invalid cron: {$expr}");
        return new self(
            self::field($p[0],0,59),
            self::field($p[1],0,23),
            self::field($p[2],1,31),
            self::field($p[3],1,12),
            self::field($p[4],0,6)
        );
    }

    public function isDue(\DateTimeImmutable $dt): bool
    {
        $min=(int)$dt->format('i');
        $hr=(int)$dt->format('G');
        $dom=(int)$dt->format('j');
        $mon=(int)$dt->format('n');
        $dow=(int)$dt->format('w');
        return isset($this->m[$min], $this->h[$hr], $this->dom[$dom], $this->mon[$mon], $this->dow[$dow]);
    }

    /** @return array<int,bool> */
    private static function field(string $f, int $min, int $max): array
    {
        $f = trim($f);
        $set=[];
        if ($f==='*') { for($i=$min;$i<=$max;$i++) $set[$i]=true; return $set; }
        foreach (explode(',', $f) as $c) {
            $c=trim($c);
            if ($c==='') continue;
            if (str_contains($c,'/')) {
                [$base,$stepS]=explode('/',$c,2);
                $step=max(1,(int)$stepS);
                if ($base==='*') { for($i=$min;$i<=$max;$i+=$step) $set[$i]=true; continue; }
                if (str_contains($base,'-')) {
                    [$a,$b]=explode('-',$base,2);
                    $a=max($min,min($max,(int)$a));
                    $b=max($min,min($max,(int)$b));
                    for($i=$a;$i<=$b;$i+=$step) $set[$i]=true;
                    continue;
                }
                $v=(int)$base; if ($v>=$min && $v<=$max) $set[$v]=true;
                continue;
            }
            if (str_contains($c,'-')) {
                [$a,$b]=explode('-',$c,2);
                $a=max($min,min($max,(int)$a));
                $b=max($min,min($max,(int)$b));
                for($i=$a;$i<=$b;$i++) $set[$i]=true;
                continue;
            }
            $v=(int)$c; if ($v>=$min && $v<=$max) $set[$v]=true;
        }
        if (!$set) throw new \InvalidArgumentException("Invalid cron field: {$f}");
        return $set;
    }
}
