<?php

class Props {
    private $_props = [];


    public function __get(string $name) {
        return $this->_props[$name];
    }


    public function __set(string $name, $value) {
        $this->_props[$name] = $value;
    }


    public function to_graphviz() : string {
        $strs = [];
        foreach ($this->_props as $key => $value)
            if ($value !== "" && $value !== null)
                $strs[] = "{$key}=\"{$value}\"";
        return implode("; ", $strs);
    }
}

class Node {
    private $out_edges = [];
    public $name;
    public $props;

    public $always = false;
    public $size = 0;
    public $module = null;

    public function __construct(string $name) {
        $this->name = $name;
        $this->props = new Props;
    }


    public function to_graphviz() : string {
        $comment = "";
        if ($this->size != 0)
            $comment = " // size={$this->size}";

        $props = $this->props->to_graphviz();
        return "\"{$this->name}\" [ {$props} ] {$comment}\n";
    }


    public function add_edge(Edge $edge) {
        if ($edge->from !== $this)
            throw new \LogicException("Invalid edge for node {$this->name}");
        $this->out_edges[] = $edge;
    }


    public function get_out_edges() : array {
        return $this->out_edges;
    }



    /**
     * Breadth-first "search" from the current node.
     * @return Array of nodes visited.
     */
    public function bfs() : array {
        $seen = [];
        $to_process = [$this];
        while ($currnode = array_shift($to_process)) {
            if (in_array($currnode, $seen, true))
                continue;
            $seen[] = $currnode;
            foreach ($currnode->get_out_edges() as $edge)
                $to_process[] = $edge->to;
        }
        return $seen;
    }
}


class Edge {
    public $from, $to;
    public $props;

    public $indirect = true;

    public function __construct(Node $from, Node $to) {
        $this->from = $from;
        $this->to = $to;
        $this->props = new Props;
    }


    public function to_graphviz() : string {
        $props = $this->props->to_graphviz();
        return "\"{$this->from->name}\" -> \"{$this->to->name}\" [ {$props} ]\n";
    }
}


class Graph {
    private $nodes = [];
    private $legend = [];

    public $nodeprops;


    public function __construct() {
        $this->nodeprops = new Props;
    }


    public function has_node(string $name) : bool {
        return isset($this->nodes[$name]);
    }


    public function create_node(string $name) : Node {
        if ($this->has_node($name))
            throw new \LogicException("Node already exists: {$name}");
        $newnode = new Node($name);
        $this->nodes[$name] = $newnode;
        return $newnode;
    }


    public function get_node(string $name) : Node {
        if (!isset($this->nodes[$name]))
            throw new \LogicException("No such node: {$name}");
        return $this->nodes[$name];
    }


    public function get_nodes() : array {
        return $this->nodes;
    }


    public function create_edge(Node $from, Node $to) : Edge {
        $newedge = new Edge($from, $to);
        $from->add_edge($newedge);
        return $newedge;
    }


    public function find_all_edges() : array {
        $edges = [];
        foreach ($this->nodes as $node)
            $edges = array_merge($edges, $node->get_out_edges());
        return $edges;
    }


    public function find_edges_of_cycles() : array {
        $edges_of_cycles = [];
        /* for each $startnode, mark all cycle edges pointing to it */
        foreach ($this->nodes as $startnode) {
            /* do bfs from this node */
            $visited = [];
            $to_process = [$startnode];
            while ($currnode = array_shift($to_process)) {
                if (in_array($currnode, $visited, true))
                    continue;
                $visited[] = $currnode;
                foreach ($currnode->get_out_edges() as $edge) {
                    $to_process[] = $edge->to;
                    if ($edge->to === $startnode)
                        $edges_of_cycles[] = $edge;
                }
            }
        }
        return $edges_of_cycles;
    }


    public function add_legend(string $legend, string $module) : Node {
        $legendnode = new Node($legend);
        $legendnode->module = $module;
        $this->legend[] = $legendnode;
        return $legendnode;
    }


    public function get_legends() : array {
        return $this->legend;
    }


    private function legends_to_graphviz() : string {
        $out = "";
        if (count($this->legend) > 0) {
            $out .= "subgraph cluster_legend {\n";
            $out .= "  rank = \"source\"; style = \"filled\"; fillcolor = \"#EEEEEE\";\n";
            $out .= "  node [ style = \"filled\"; shape = \"note\"; ]\n";
            foreach ($this->legend as $idx => $legendnode) {
                $out .= "  \"cluster_legend_{$idx}\" [ label = \"{$legendnode->name}\"; fillcolor = \"{$legendnode->module}\"; ]\n";
            }
            $out .= "}\n";
        }
        return $out;
    }


    private function nodes_to_graphviz() : string {
        $out = "";
        foreach ($this->nodes as $node)
            $out .= $node->to_graphviz();
        return $out;
    }


    private function edges_to_graphviz() {
        $out = "";
        foreach ($this->nodes as $node)
            foreach ($node->get_out_edges() as $edge)
                $out .= $edge->to_graphviz();
        return $out;
    }


    public function to_graphviz() : string {
        $out = "strict digraph {\n";

        $props = $this->nodeprops->to_graphviz();
        $out .= "node [ {$props} ]\n";

        $out .= $this->legends_to_graphviz();
        $out .= $this->nodes_to_graphviz();
        $out .= $this->edges_to_graphviz();

        $out .= "}\n";
        return $out;
    }
}


class RTLReader {
    private $ignore = '/(^_GLOBAL_|^__static_initialization_|\bstd::)/';
    private $always_on_graph = ["fopen", "fclose", "malloc", "free", "exit"];
    private $translate_dict = [
        "calloc" => "malloc",
        "realloc" => "malloc",
    ];
    private $demangle_names = true;


    private function demangle(string $mangled) : string {
        static $cache = [];

        if (substr($mangled, 0, 2) != "_Z")
            return $mangled;
        if (isset($cache[$mangled]))
            return $cache[$mangled];
        $demangled = exec("c++filt " . escapeshellarg($mangled), $output, $retval);
        if ($retval != 0)
            throw new \RuntimeException("Cannot demangle name: {$mangled}");
        $cache[$mangled] = $demangled;
        return $demangled;
    }


    private function translate(string $name) : string {
        return $this->translate_dict[$name] ?? $name;
    }


    private function ignore_func(string $name) : bool {
        return preg_match($this->ignore, $name);
    }


    private function match_func_def(string $text, string & $name = null) : bool {
        /* first the normal name, with spaces and parenthesis, then after the parenthesis comes the mangled name until the comma */
        $success = preg_match('/^;; Function .*? \(([^,]+), funcdef_no=/', $text, $m);
        if ($success) {
            $name = $m[1];
            if ($this->demangle_names)
                $name = $this->demangle($name);
        }
        return $success;
    }


    private function match_func_refer(string $text, string & $name = null) : bool {
        $success = preg_match('/symbol_ref:DI \("([^"]+)"\) \[[^]]+\]  <function_decl /', $text, $m);
        if ($success) {
            $name = $m[1];
            if ($this->demangle_names)
                $name = $this->demangle($name);
        }
        return $success;
    }


    private function match_call(string $text) : bool {
        return preg_match('/\s+\(call /', $text);
    }


    private function match_insn(string $text) : bool {
        return preg_match('/^\((insn|call_insn) /', $text);
    }


    private function add_always_functions(Graph $g) {
        foreach ($this->always_on_graph as $name) {
            $node = $g->create_node($name);
            $node->always = true;
        }
    }


    private function add_functions(Graph $g, array $input, int $module) : int {
        $sizesum = 0;
        $func = null;
        foreach ($input as $line) {
            if ($this->match_func_def($line, $name)) {
                $func = null;
                if (!$this->ignore_func($name) && !in_array($name, $this->always_on_graph)) {
                    if ($g->has_node($name))
                        $func = $g->get_node($name);
                    else {
                        $func = $g->create_node($name);
                        $func->module = $module;
                    }
                }
            }
            else if ($this->match_insn($line)) {
                $sizesum += 1;
                if ($func != null)
                    $func->size += 1;
            }
        }
        return $sizesum;
    }


    private function add_calls(Graph $g, array $input) {
        $caller = null;
        foreach ($input as $line) {
            if ($this->match_func_def($line, $name)) {
                $caller = null;
                if ($g->has_node($name))    /* some might have been filtered out */
                    $caller = $g->get_node($name);
            }
            else if ($this->match_func_refer($line, $name)) {
                $name = $this->translate($name);
                if ($caller != null && $g->has_node($name)) {   /* some might have been filtered out */
                    $callee = $g->get_node($name);
                    $edge = $g->create_edge($caller, $callee);
                    if ($this->match_call($line))
                        $edge->indirect = false;
                }
            }
        }
    }


    private function add_module(Graph $g, string $filename, int $sizesum, int $module) {
        $filename = preg_replace('/(\.\d+r)?\.expand$/', '', $filename);
        $g->add_legend(basename($filename), $module);
    }


    public function create_graph_from_rtl(array $filenames) : Graph {
        $g = new Graph;

        $this->add_always_functions($g);

        $module = 1;
        foreach ($filenames as $filename) {
            $input = file($filename);
            $sizesum = $this->add_functions($g, $input, $module);
            $this->add_module($g, $filename, $sizesum, $module);
            $module += 1;
        }

        foreach ($filenames as $filename) {
            $input = file($filename);
            $this->add_calls($g, $input);
        }

        return $g;
    }
}


class Expand2Graph {
    public function process(array $infiles) : Graph {
        /* read files */
        $reader = new RTLReader;
        $g = $reader->create_graph_from_rtl($infiles);

        /* graph props */
        $g->nodeprops->shape = 'box';
        $g->nodeprops->style = 'filled';
        $g->nodeprops->colorscheme = 'set312';
        /* set node colors */
        foreach ($g->get_nodes() as $node) {
            if ($node->always) {
                $node->props->shape = 'ellipse';
                $node->props->fillcolor = "#EEEEEE";
            }
        }
        foreach (array_merge($g->get_nodes(), $g->get_legends()) as $node) {
            if ($node->module)
                $node->props->fillcolor = ($node->module - 1) % 12 + 1;
        }
        /* set heights */
        foreach ($g->get_nodes() as $node)
            if ($node->size != 0)
                $node->props->height = sqrt($node->size) / 10;
        /* indirect edges are dashed */
        foreach ($g->find_all_edges() as $edge) {
            if ($edge->indirect)
                $edge->props->style = 'dashed';
        }
        /* cycles' edges are red */
        foreach ($g->find_edges_of_cycles() as $edge) {
            $edge->props->color = 'red';
            $edge->props->style = 'bold';
        }
        /* nodes unreachable from main() are dotted */
        $all = $g->get_nodes();
        if ($g->has_node('main')) {
            $reachable = $g->get_node('main')->bfs();
            foreach ($all as $node)
                if (!in_array($node, $reachable, true)) {
                    $node->props->style = 'dashed, filled, radial';
                    $node->props->fillcolor = "white:" . $node->props->fillcolor;
                }
        }

        return $g;
    }
}


/**
 * Process C/C++/Expand files,create compiler output in specified working directory..
 */
class ProcessFiles {
    private $wd;

    public function __construct(string $wd) {
        $this->wd = $wd;
    }

    private function process_files(string $command, array $files) {
        $cwd = getcwd();
        foreach ($files as $file) {
            $file = realpath($file);
            chdir($this->wd);   /* cannot set gcc rtl-expand filename... so chdir to temp dir */
            $cmd = sprintf($command, escapeshellarg($file));
            exec($cmd, $output, $retval);
            if ($retval != "")
                error_log(implode("\n", $output));
            chdir($cwd);
        }
    }

    public function process_c(array $files) {
        return $this->process_files("gcc -w -c -fdump-rtl-expand -o /dev/null %s", $files);
    }

    public function process_cpp(array $files) {
        return $this->process_files("g++ -w -c -fdump-rtl-expand -o /dev/null %s", $files);
    }

    public function process_expand(array $files) {
        return $this->process_files("cp %s .", $files);
    }
}

function process_args(array $argv) : array {
    $cfiles = [];
    $cppfiles = [];
    $expandfiles = [];
    $svgfile = "output.svg";
    for ($i = 1; $i < count($argv); ++$i) {
        if (substr($argv[$i], -2) == ".c")
            $cfiles[] = $argv[$i];
        else if (substr($argv[$i], -4) == ".cpp")
            $cppfiles[] = $argv[$i];
        else if (substr($argv[$i], -7) == ".expand")
            $expandfiles[] = $argv[$i];
        else if (substr($argv[$i], -4) == ".svg")
            $svgfile = $argv[$i];
        else {
            error_log("Unknown param: {$argv[$i]}");
            return [];
        }
    }
    
    return [
        'cfiles' => $cfiles,
        'cppfiles' => $cppfiles,
        'expandfiles' => $expandfiles,
        'svgfile' => $svgfile,
    ];
}

function create_svg(array $args) {
    $workdir = sys_get_temp_dir() . "/" . uniqid();
    mkdir($workdir);
    
    /* compile sources */
    $pf = new ProcessFiles($workdir);
    $pf->process_c($args['cfiles']);
    $pf->process_cpp($args['cppfiles']);
    $pf->process_expand($args['expandfiles']);

    /* process expand files */
    $e2g = new Expand2Graph;
    $g = $e2g->process(glob($workdir . "/*.expand"));
    $gv = $g->to_graphviz();

    /* save output */
    $dotfile = $workdir . "/output.gv";
    file_put_contents($dotfile, $gv);
    $command = sprintf("dot -Tsvg %s -o %s", escapeshellarg($dotfile), escapeshellarg($args['svgfile']));
    exec($command, $output, $retval);

    /* delete temp files */
    foreach (glob($workdir . "/*") as $file)
        unlink($file);
    rmdir($workdir);
}


function main(array $argv) : int {
    if (count($argv) < 2) {
        error_log("Creates a function call graph for C/C++ code.");
        error_log("Usage: {$argv[0]} <inputfiles...> [output.svg]");
        error_log("Filenames are: *.c, *.cpp (compile), *.expand (use as-is), and one output.svg.");
        error_log("File types are determined by the extension.");
        return 1;
    }

    $args = process_args($argv);
    if ($args == [])
        return 1;
    create_svg($args);

    return 0;
}


exit(main($argv));
