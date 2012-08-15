{def $current=$curNode.path|contains($link_node.nodeID)}
<li {if $current}class="current"{/if}>
	{if $curNodeID|eq($link_node.nodeID)}<span>{$link_node.name|wash|shorten(60)}</span>
	{else}<a href="../page/{$link_node.nodeID}.html" title="{$link_node.name|wash}">{$link_node.name|wash|shorten(60)}</a>{if}
</li>