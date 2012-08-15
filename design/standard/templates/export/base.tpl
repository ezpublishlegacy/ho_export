<!doctype html>
<html>
<head>
	<meta charset="utf-8">
	<title>{$node.name|wash} - {$title}</title>
	<link rel="stylesheet" type="text/css" href={"stylesheets/export.css"|ezdesign} />
</head>
<body>
	<div id="wrapper">
		<div id="header">
			<h1><a id="homelink" href="../index.html"><img src={concat( "images/", $logo)|ezdesign}/></a>{$title}</h1>
		</div>
		<div id="nav">
			{include uri="design:export/menu.tpl"}
		</div>
		<div id="main">
			{node_view_gui content_node=$node view=full page_limit=99999}
		</div>
		<div id="footer">
			<p>{$footer}</p>
		</div>
	</div>
</body>
</html>