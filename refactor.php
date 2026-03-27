<?php

ini_set('memory_limit', '1G');

require __DIR__ . '/vendor/autoload.php';

use Forte\Facades\Forte;
use Forte\Rewriting\Rewriter;
use Forte\Rewriting\NodePath;
use Forte\Rewriting\Visitor;
use Forte\Rewriting\Builders\Builder;

const MODE_DUMP_CSS = 1;
const MODE_DUMP_TAG = 2;

$verbose = false;


class MyRewriter extends Visitor
{
    // public $updated_files = [];
    public $styles = [];
    public $tags = [];
    private $fp = null;
    
    public function __construct($fp, $backlog)
    {
        global $mode;
        $this->fp = $fp;            // currently processed file
        if ($mode == MODE_DUMP_CSS) {
            $this->styles = $backlog;    // shared list of already seen styles
        }
    }

    private function split_inline($attribute): Array
    {
        $to_keep = [];
        $to_move = [];
        global $mode;

        // FIXME: if a complex {{ ; }} as a semicolon inside, it breaks
        // just keep the whole thing
        $complex_with_semicolon = '[{{.*;.*}}|{!!.*;.*!!}|{{{.*;.*}}}]';
        preg_match($complex_with_semicolon, $attribute, $matches);
        if ($matches) {
            $to_keep[] = $attribute;
            return [$to_keep, $to_move];
        }

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
        global $mode;

        if (! $path->isElement()) return;

        if ($mode == MODE_DUMP_TAG) {
            if ($path->asElement()->tagNameText() == 'svg') {
                $html = trim($path->asElement()->render());
                $this->tags = [preg_replace('/\>\s+\</m', '><', $html)];
            }

            $ne = Builder::element('p')->text('FUCK IT');
            $path->appendChild($ne);

        } else if ($path->hasAttribute('style')) {
            // get some context
            $ctx_origin = $path->asElement()->tagNameText();

            $ctx_classes = [];

            $tmp = [];
            foreach ($path->asElement()->ancestors() as $ancestor) {
                if ($ancestor != null and $ancestor->asElement() != null) {
                    $previous_tag = end($tmp);
                    $current_tag = $ancestor->asElement()->tagNameText();
                    if ($previous_tag != $current_tag) {
                        // if (in_array($tag_name, ['form', 'table', 'p'])) {
                            array_push($tmp, $current_tag);
                        // }
                    }
                }
            }
            // display the path in a logical order (root on left)
            $ctx_paths = [implode('>', array_reverse($tmp))];
            // $ctx_paths = $tmp;


            $inline_style = $path->getAttribute('style');

            // get attributes attached to this html element
            // ex:
            //   attribute: id="wordpress-fields"
            //   attribute: style="display: {{ old('wordpress_site_requested') ? 'block' : 'none' }}; padding-top: 12px; border-top: 1px solid #e2e8f0;"
            // FIXME: should find a better way to get only the 'style' attribute from current element
            foreach ($path->asElement()->attributes() as $attribute) {
                
                if ($attribute->nameText() === 'class') {
                    $ctx_classes = explode(' ', $attribute->valueText());
                };

                if ($attribute->nameText() !== 'style') continue;

                $to_move = [];
                $to_keep = [];

                // FIXME: seems to be buggy with "{{ ($errors->any() || old('nom'))"
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
                $hash = "H" . md5($external_style);
                $path->addClass($hash);

                // this given inline style has never been seen
                if (!array_key_exists($hash, $this->styles)) {
                    $this->styles[$hash] = array(
                        'inline_style' => $external_style,
                        'hash' => $hash,
                        'source_files' => array($this->fp),
                        'ctx_origin' => $ctx_origin,
                        'ctx_classes' => $ctx_classes,
                        'ctx_paths' => $ctx_paths,
                    );

                } else {
                    $asf = $this->styles[$hash]['source_files'];
                    if (! in_array($this->fp, $asf)) {
                        $this->styles[$hash]['source_files'] = array_merge($asf, [$this->fp]);
                    }

                    $existing_classes = $this->styles[$hash]['ctx_classes'];
                    foreach ($ctx_classes as $a_class) {
                        if (! in_array($a_class, $existing_classes)) {
                            array_push($this->styles[$hash]['ctx_classes'], $a_class);
                        }
                    }

                    $existing_paths = $this->styles[$hash]['ctx_paths'];
                    foreach ($ctx_paths as $a_path) {
                        if (! in_array($a_path, $existing_paths)) {
                            array_push($this->styles[$hash]['ctx_paths'], $a_path);
                        }
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

function feedback(String $str): void
{
    global $verbose;

    if ($verbose) {
        echo $str;
    }
}

function format_bloc(String $hash, String $style): String
{
    global $print_oneline;

    if ($print_oneline) {
        $_EOL = "";
    } else {
        $_EOL = PHP_EOL;
    }
    $out = '.' . $hash . ' {' . $_EOL;
    foreach (explode(';', $style ) as $line) {
        if (strlen($line) > 0) {
            $out = $out . "    ".$line.';' . $_EOL;
        }
    }
    $out = $out . '}' . $_EOL;

    return $out;
}


// command line handling
$shortopts  = "s:t:c:i:1::d::v::";
$longopts   = ["source:","target:","css::","include::","oneline::","dump-tag","verbose"];
$options = getopt($shortopts, $longopts);
$mode = MODE_DUMP_CSS;

if (isset($options["v"])) {
    $verbose = true;

} elseif (isset($options["verbose"])) {
    $verbose = true;

}

if (isset($options["s"])) {
    $source_directory = $options["s"];

} elseif (isset($options["source"])) {
    $source_directory = $options["source"];

} else {
    echo "Error: source directory argument is missing.\n";
    exit(1);
}


if (isset($options["t"])) {
    $target_directory = $options["t"];

} elseif (isset($options["target"])) {
    $target_directory = $options["target"];

} else {
    feedback("info: target directory argument is missing, will output on console\n");
    $target_directory = null;
}


if (isset($options["i"])) {
    $filename_filter = $options["i"];

} elseif (isset($options["include"])) {
    $filename_filter = $options["include"];

} else {
    $filename_filter = ".blade.php";
}


if (isset($options["1"])) {
    $print_oneline = true;

} elseif (isset($options["oneline"])) {
    $print_oneline = true;

} else {
    $print_oneline = false;
}

if (isset($options["d"])) {
    $mode = MODE_DUMP_TAG;

} elseif (isset($options["dump"])) {
    $mode = MODE_DUMP_TAG;
}


if (isset($options["c"])) {
    $target_css = $options["c"];

} elseif (isset($options["css"])) {
    $target_css = $options["css"];

} else {
    $target_css = null;
}


// main processing loop
$styles = [];
$tags = [];
if (!is_dir($source_directory)) {
    exit('Invalid source directory path');
}

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source_directory));
foreach ($rii as $file) {
    if ($file->isDir()){ 
        continue;
    }

    if (str_ends_with($file->getPathname(), $filename_filter)) {
        feedback("computing " . $file->getPathname() . PHP_EOL);
        $doc = Forte::parseFile($file->getPathname());

        $rewriter = new Rewriter;
        if ($mode == MODE_DUMP_CSS) {
            $myRewriter = new MyRewriter($file->getPathname(), $styles);
            $rewriter->addVisitor($myRewriter);

            $new_doc = $rewriter->rewrite($doc);        
            $styles = array_merge($styles, $myRewriter->styles);

            feedback("style extracted: " . count($styles) . PHP_EOL);
            if (! isset($target_directory)) {
                feedback("=============== UPDATED FILE: " . $file->getPathname() . PHP_EOL);
                echo $new_doc;

            } else {
                $new_doc_path = str_replace($source_directory, $target_directory, $file->getPathname());
                if (!is_dir(dirname($new_doc_path))) {
                    mkdir(dirname($new_doc_path), 0750, true);
                }
                file_put_contents($new_doc_path, $new_doc);
            }

        } else if ($mode == MODE_DUMP_TAG) {
            $myRewriter = new MyRewriter($file->getPathname(), $tags);
            $rewriter->addVisitor($myRewriter);

            $new_doc = $rewriter->rewrite($doc);
            $tags = array_merge($tags, $myRewriter->tags);
            echo $new_doc;

        }
        unset($myRewriter);
    }
}

if ($mode == MODE_DUMP_CSS) {    
    if (! isset($target_directory)) {
        feedback("=============== ALL IN ONE CSS FILE:". PHP_EOL);
    }

    $css = "";
    foreach ($styles as $o) {
        $header = "";
        foreach ($o['source_files'] as $sf) {
            $header = $header . "/* from: " . $sf . "*/" . PHP_EOL;
        }
        $header = $header . "/* context-origin: " . $o['ctx_origin'] . " */" . PHP_EOL;
        foreach ($o['ctx_classes'] as $x) {
            $header = $header . "/* context-class: " . $x . " */" . PHP_EOL;
        }
        foreach ($o['ctx_paths'] as $x) {
            $header = $header . "/* context-path: " . $x . " */" . PHP_EOL;
        }
        $css = $css . $header . format_bloc($o['hash'], $o['inline_style']) . PHP_EOL;
    }

    if (! isset($target_css)) {
        echo $css;
    } else {
        if (!is_dir(dirname($target_css))) {
            mkdir(dirname($target_css), 0750, true);
        }
        file_put_contents($target_css, $css);
    }

} else {
    foreach($tags as $tag) {
        echo "$tag" . PHP_EOL;
    }
}
