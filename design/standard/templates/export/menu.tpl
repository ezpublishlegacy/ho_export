{def $curNodeID=$node.node_id
	$curNode = $nodeList[concat('node_',$curNodeID)]
}
<ul class="menulist">
{foreach $nodeList as $menuNode max 1}
	{include uri="design:myexport/menulink.tpl" link_node=$menuNode}
	{foreach $menuNode.children as $subNode}
		{if is_set($nodeList[concat('node_',$subNode)])}
			{include uri="design:myexport/menulink.tpl" link_node=$nodeList[concat('node_',$subNode)]}
		{/if}
	{/foreach}
{/foreach}
</ul>