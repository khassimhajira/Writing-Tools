<?php

class Am_View_Helper_Markdown extends Zend_View_Helper_Abstract
{
    public function markdown($string)
    {
        $spaces = array_map(fn($line) => strlen($line) - strlen(ltrim($line)), explode("\n", $string));

        $arr = array_map('trim', explode("\n", $string));
        $isUl = false;
        $uls = [];
        $isOl = false;
        $ols = [];
        $isCode = false;
        $codes = [];
        foreach ($arr as $k => & $line) {
            if (substr($line, 0, 3) == '```' && $isCode) {
                unset($arr[$k]);
                $isCode = false;
                continue;
            }
            if (substr($line, 0, 3) == '```') {
                $isCode = true;
                $codes[] = [];
                $codes[count($codes) - 1][] = substr($line, 3);
                $line = '<CODE__' . (count($codes) - 1) . '>';
                continue;
            }
            if ($isCode) {
                $codes[count($codes) - 1][] = str_repeat(' ', $spaces[$k]) . $line;
                unset($arr[$k]);
                continue;
            }

            $line = preg_replace('/\*\*(.*?)\*\*/', '<strong>\1</strong>', $line);
            $line = preg_replace('/([^*])\*(.*?)\*([^*])/', '\1<em>\2</em>\3', $line);
            $line = preg_replace('/`([^`]+?)`/', '<code>\1</code>', $line);
            if (substr($line, 0, 2) == '* ') {
                if ($isUl) {
                    $uls[count($uls) - 1][] = substr($line, 2);
                    unset($arr[$k]);
                } else {
                    $isUl = true;
                    $uls[] = [];
                    $uls[count($uls) - 1][] = substr($line, 2);
                    $line = '<UL__' . (count($uls) - 1) . '>';
                }
            } else {
                $isUl = false;
            }
            if (preg_match('/^(\d+\. )/', $line, $m)) {
                if ($isOl) {
                    $ols[count($ols) - 1][] = substr($line, strlen($m[1]));
                    unset($arr[$k]);
                } else {
                    $isOl = true;
                    $ols[] = [];
                    $ols[count($ols) - 1][] = substr($line, strlen($m[1]));
                    $line = '<OL__' . (count($ols) - 1) . '>';
                }
            } else {
                $isOl = false;
            }
            if (substr($line, 0, 5) == '#### ') {
                $line = "<h4>" . substr($line, 4) . "</h4>";
            }
            if (substr($line, 0, 4) == '### ') {
                $line = "<h3>" . substr($line, 4) . "</h3>";
            }
            if (substr($line, 0, 3) == '## ') {
                $line = "<h2>" . substr($line, 3) . "</h2>";
            }
            if (substr($line, 0, 2) == '# ') {
                $line = "<h1>" . substr($line, 2) . "</h1>";
            }
            if ($line == '---' || $line == '***') {
                $line = "<hr />";
            }

            if (substr($line, 0, 1) != '<' || substr($line, 0, 2) == '<a') {
                $line .= "\n";
            }
        }

        $replace = [];
        foreach ($uls as $k => $items) {
            $replace["<UL__$k>"] = sprintf("<ul>%s</ul>", implode("", array_map(function($_) {return "<li>$_</li>";}, $items)));
        }
        foreach ($ols as $k => $items) {
            $replace["<OL__$k>"] = sprintf("<ol>%s</ol>", implode("", array_map(function($_) {return "<li>$_</li>";}, $items)));
        }
        foreach ($codes as $k => $items) {
            $replace["<CODE__$k>"] = sprintf('<pre class="am-code am-code-copy-to-clipboard">%s</pre>', implode("\n", $items));
        }
        $string = implode("", $arr);
        return str_replace(array_keys($replace), array_values($replace), $string);
    }
}