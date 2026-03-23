<?php

require __DIR__ . '/vendor/autoload.php';

use Forte\Facades\Forte;
use Forte\Rewriting\Rewriter;
use Forte\Rewriting\NodePath;
use Forte\Rewriting\Visitor;


class MyRewriter extends Visitor
{
    // public $updated_files = [];
    public $styles = [];
    private $fp = null;

    public function __construct($fp, $styles)
    {
        $this->fp = $fp;            // currently processed file
        $this->styles = $styles;    // shared list of already seen styles
    }

    private function split_inline($attribute): Array
    {
        $to_keep = [];
        $to_move = [];

        foreach (explode(';', $attribute ) as $line) {
            $complex_regex = '[{{.*}}|{!!.*!!}|{{{.*}}}|@if|@else|@elseif|@endif]';
            preg_match($complex_regex, $line, $matches);

            if ($matches) {
                $to_keep[] = $line;

            } else {
                $to_move[] = $line;
            }
        }

        return [$to_keep, $to_move];
    }


    public function enter(NodePath $path): void
    {
        if (! $path->isElement()) return;

        if ($path->hasAttribute('style')) {
            $inline_style = $path->getAttribute('style');

            // get attributes attached to this html element
            // ex:
            //   attribute: id="wordpress-fields"
            //   attribute: style="display: {{ old('wordpress_site_requested') ? 'block' : 'none' }}; padding-top: 12px; border-top: 1px solid #e2e8f0;"
            // FIXME: should find a better way to get only the 'style' attribute from current element
            foreach ($path->asElement()->attributes() as $attribute) {
                if ($attribute->nameText() !== 'style') continue;

                $to_move = [];
                $to_keep = [];

                if ($attribute->hasComplexName()) {
                    // if the key is complex, we cannot do anything
                    continue;

                } else if ($attribute->hasComplexValue()) {
                    // Forte doesn't understand css grammar / split css string in individual nodes
                    // we have to figure that out ourself
                    [ $to_keep, $to_move ] = $this->split_inline($attribute->valueText());

                } else {
                    // shortcut if no complex value, just move out the whold string
                    array_push($to_move, $attribute->valueText());
                }
            };

            // write back those to keep in place, removing others
            $path->removeAttribute('style');

            // replace the style with the new (shortened) value
            if (count($to_keep) > 0) {
                $new_inline_style = implode(';', $to_keep) . ';';                
                $path->setAttribute('style', $new_inline_style);
                }

            // add the class pointing to the rest of the style
            if (count($to_move) > 0) {
                $external_style = implode(';', $to_move) . ';';
                $hash = md5($external_style);
                $path->addClass($hash);

                // this given inline style has never been seen
                if (!array_key_exists($hash, $this->styles)) {
                    $this->styles[$hash] = array(
                        'inline_style' => $external_style,
                        'hash' => $hash,
                        'source_files' => array($this->fp)
                    );

                } else {
                    $asf = $this->styles[$hash]['source_files'];
                    if (! in_array($this->fp, $asf)) {
                        $this->styles[$hash]['source_files'] = array_merge($asf, [$this->fp]);
                    }
                }
            }
        }
    }

    public function leave(NodePath $path): void
    {
        // echo 'Leaving '.$path->nodeIndex().': '.get_class($path->node()).PHP_EOL;
    }
}


function format_bloc(String $hash, String $style): void
{
    echo $hash.': {'.PHP_EOL;
    foreach (explode(';', $style ) as $line) {
        if (strlen($line) > 0) {
            echo "    ".$line.';'.PHP_EOL;
        }
    }
    echo '};'.PHP_EOL;
}


$styles = [];
$directory = '/tmp/views';
if (!is_dir($directory)) {
    exit('Invalid directory path');
}

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
foreach ($rii as $file) {
    if ($file->isDir()){ 
        continue;
    }

    // if (str_ends_with($file->getPathname(), 'tenant-dashboard.blade.php')) {
    if (str_ends_with($file->getPathname(), '.blade.php')) {
        echo "computing " . $file->getPathname() . PHP_EOL;
        $doc = Forte::parseFile($file->getPathname());

        $rewriter = new Rewriter;
        $myRewriter = new MyRewriter($file->getPathname(), $styles);
        $rewriter->addVisitor($myRewriter);

        $newDoc = $rewriter->rewrite($doc);
        
        $styles = array_merge($styles, $myRewriter->styles);

        echo "style extracted: " . count($styles) . PHP_EOL;
        // echo $newDoc;
    }
}

foreach ($styles as $o) {
    echo PHP_EOL;
    foreach ($o['source_files'] as $sf) {
        echo "// from: " . $sf . PHP_EOL;
    }
    format_bloc($o['hash'], $o['inline_style']);
}
