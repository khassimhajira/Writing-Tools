<?php

class Am_Grid_Field_Decorator_CopyToClipboard extends Am_Grid_Field_Decorator_Abstract
{
    static $initDone = false;

    public function render(&$out, $obj, $controller)
    {
        $out = preg_replace_callback(
            '|(<td.*?>)(.+)(</td>)|s',
            function ($m) {
                return sprintf(
                    '%s<span class="std-am-copy-to-clipboard" data-value="%s">%s</span><a href="javascript:;" title="%s" class="std-am-copy-to-clipboard-trigger"></a>%s',
                    $m[1],
                    Am_Html::escape($m[2]),
                    $m[2],
                    Am_Html::escape(___('Copy To Clipboard')),
                    $m[3]
                );
            },
            $out
        );
    }

    function renderStatic(& $out)
    {
        if (self::$initDone) return;
        self::$initDone = true;

        $out .= <<<CUT
<style>
.std-am-copy-to-clipboard-highlight {
    background: #fcf2bf;
}

.std-am-copy-to-clipboard-trigger {
    background: url(data:image/svg+xml;base64,PHN2ZyBhcmlhLWhpZGRlbj0idHJ1ZSIgZm9jdXNhYmxlPSJmYWxzZSIgZGF0YS1wcmVmaXg9ImZhciIgZGF0YS1pY29uPSJjb3B5IiBjbGFzcz0ic3ZnLWlubGluZS0tZmEgZmEtY29weSBmYS13LTE0IiByb2xlPSJpbWciIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgdmlld0JveD0iMCAwIDQ0OCA1MTIiPjxwYXRoIGZpbGw9ImN1cnJlbnRDb2xvciIgZD0iTTQzMy45NDEgNjUuOTQxbC01MS44ODItNTEuODgyQTQ4IDQ4IDAgMCAwIDM0OC4xMTggMEgxNzZjLTI2LjUxIDAtNDggMjEuNDktNDggNDh2NDhINDhjLTI2LjUxIDAtNDggMjEuNDktNDggNDh2MzIwYzAgMjYuNTEgMjEuNDkgNDggNDggNDhoMjI0YzI2LjUxIDAgNDgtMjEuNDkgNDgtNDh2LTQ4aDgwYzI2LjUxIDAgNDgtMjEuNDkgNDgtNDhWOTkuODgyYTQ4IDQ4IDAgMCAwLTE0LjA1OS0zMy45NDF6TTI2NiA0NjRINTRhNiA2IDAgMCAxLTYtNlYxNTBhNiA2IDAgMCAxIDYtNmg3NHYyMjRjMCAyNi41MSAyMS40OSA0OCA0OCA0OGg5NnY0MmE2IDYgMCAwIDEtNiA2em0xMjgtOTZIMTgyYTYgNiAwIDAgMS02LTZWNTRhNiA2IDAgMCAxIDYtNmgxMDZ2ODhjMCAxMy4yNTUgMTAuNzQ1IDI0IDI0IDI0aDg4djIwMmE2IDYgMCAwIDEtNiA2em02LTI1NmgtNjRWNDhoOS42MzJjMS41OTEgMCAzLjExNy42MzIgNC4yNDMgMS43NTdsNDguMzY4IDQ4LjM2OGE2IDYgMCAwIDEgMS43NTcgNC4yNDNWMTEyeiI+PC9wYXRoPjwvc3ZnPg==) center center no-repeat;
    background-size: contain;
    display: inline-block;
    width: 10px;
    height: 10px;
    vertical-align: middle;
    opacity: 0.4;
    margin-left: 0.6rem;
    transition: opacity 0.2s;
}
.std-am-copy-to-clipboard-trigger.std-am-copy-to-clipboard-trigger-copied {
    background: url(data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA0NDggNTEyIj48IS0tIUZvbnQgQXdlc29tZSBGcmVlIDYuNi4wIGJ5IEBmb250YXdlc29tZSAtIGh0dHBzOi8vZm9udGF3ZXNvbWUuY29tIExpY2Vuc2UgLSBodHRwczovL2ZvbnRhd2Vzb21lLmNvbS9saWNlbnNlL2ZyZWUgQ29weXJpZ2h0IDIwMjQgRm9udGljb25zLCBJbmMuLS0+PHBhdGggZmlsbD0iIzQ4OGYzNyIgZD0iTTQzOC42IDEwNS40YzEyLjUgMTIuNSAxMi41IDMyLjggMCA0NS4zbC0yNTYgMjU2Yy0xMi41IDEyLjUtMzIuOCAxMi41LTQ1LjMgMGwtMTI4LTEyOGMtMTIuNS0xMi41LTEyLjUtMzIuOCAwLTQ1LjNzMzIuOC0xMi41IDQ1LjMgMEwxNjAgMzM4LjcgMzkzLjQgMTA1LjRjMTIuNS0xMi41IDMyLjgtMTIuNSA0NS4zIDB6Ii8+PC9zdmc+Cg==) center center no-repeat;
    color: green;
}

.std-am-copy-to-clipboard-trigger:hover {
    opacity: 1;
}
</style>
<script>
jQuery(function(){
    jQuery(document).on('click', '.std-am-copy-to-clipboard-trigger', function(){
        navigator.clipboard.writeText(this.previousSibling.dataset.value ?? this.previousSibling.innerText);
        this.classList.add('std-am-copy-to-clipboard-trigger-copied');
        setTimeout(() => this.classList.remove('std-am-copy-to-clipboard-trigger-copied'), 1500);
    })
    jQuery(document).on('mouseenter mouseout', '.std-am-copy-to-clipboard-trigger', function(){
        this.previousSibling.classList.toggle('std-am-copy-to-clipboard-highlight');
    })
})
</script>
CUT;
    }
}