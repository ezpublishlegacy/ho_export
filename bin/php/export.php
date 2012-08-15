#!/usr/bin/env php
<?php
/**
 * @package ho_export
 * @author Harry Oosterveen
 * @date 15 Aug 2012
 */

require 'autoload.php';

$cli = eZCLI::instance();
$script = eZScript::instance( array( 'description' => ( "eZ Publish Export tool\n\n" .
                                                        "Allows exporting a subtree for offline browsing."  ),
                                     'use-session' => false,
                                     'use-modules' => true,
                                     'use-extensions' => true ) );

$script->startup();

$options = $script->getOptions( "[u:]",
                                "[section]",
                                array() );
$script->initialize();

// Set some limitations
define( 'MAX_NODES', 9999 );
define( 'NODE_PREFIX', 'node_' );

// Initiate variables
$nodeList = array();        // List of all nodes to include
$nodeAliases = array();     // 

$ini = eZINI::instance();
$siteURL = $ini->variable( 'SiteSettings', 'SiteURL' );
$varDir = $ini->variable( 'FileSettings', 'VarDir' );
testdir( $varDir . '/export' );

// First argument is section in export.ini
if ( count( $options['arguments'] ) < 1 ) {
	$script->shutdown( 1, "No section specified." );	
}

$section = $options['arguments'][0];

$ini = eZINI::instance( 'export.ini');

if( !$ini->hasSection( $section )) {
	$script->shutdown( 1, "The section '{$section}' does not exist in export.ini." );
}

$topNodeID = $ini->variable( $section, 'TopNode' );
if( $topNodeID === false ) {
	$script->shutdown( 1, "No TopNode specified in export.ini" );
}

$template = $ini->variable( $section, 'Template' );
if( $template === false ) {
	$script->shutdown( 1, "No Template specified in export.ini" );
}

if( !empty( $options['u'] )) {
	$user = eZUser::fetchByName( $options['u'] );
	$user->loginCurrent();
}

$destination = sprintf( "%s/export/%s", $varDir, $section );

testdir( $destination );
testdir( $destination . '/page' );
testdir( $destination . '/file' );
testdir( $destination . '/image' );
testdir( $destination . '/design' );

// Create index.html page
$page = redirectPage( sprintf( 'page/%d.html', $topNodeID ));
file_put_contents(  $destination . '/index.html', $page );

// Recursively add nodes
// This will fill the array $nodeList,
//   with node numbers with prefix as keys, 
//   and an array of children main node id as value

$topNode = eZContentObjectTreeNode::fetch( $topNodeID );
addNode( $topNode );

$cli->output( sprintf( "\r\nTitle: %s", $ini->variable( $section, 'Title' )));
$cli->output( sprintf( "Found %d nodes.", count( $nodeList )));

$tpl = eZTemplate::factory();
$tpl->setVariable( 'nodeList', $nodeList );
$tpl->setVariable( 'title', $ini->variable( $section, 'Title' ));
$tpl->setVariable( 'logo', $ini->variable( $section, 'Logo' ));
$tpl->setVariable( 'footer', $ini->variable( $section, 'Footer' ));

foreach( $nodeList as $node )
{
	$nodeID = $node['nodeID'];
	if( $options['verbose'] ) {
		$cli->notice( sprintf( 'Node %5d: %s', $nodeID, $node['name'] ));
	}
	
	// Process template using this node
	// Results is text string $page
	$tpl->setVariable( 'node', eZContentObjectTreeNode::fetch( $node['nodeID'] ) );
	$page = $tpl->fetch( "design:" . $template );

	// Replace local links
	$page = preg_replace_callback( '!(src|href|action)="(/[^"]*)"!', 'replaceLink', $page );

	// Remove onclick attributes
	$page = preg_replace( '/\s+onclick=\"javascript:[^\"]+\"/i', '', $page );

	// Save page
	$filename = sprintf( '%s/page/%d.html', $destination, $nodeID );
	file_put_contents( $filename, $page );
}

$cli->output( sprintf( 'Output at http://%s/%s/index.html', $siteURL, $destination ));

// Create autorun file and shortcut icon
$icon = $ini->variable( $section, 'Favicon' );
if( !file_exists( $icon )) {
	$cli->error( sprintf( 'Icon file %s not found', $icon ));
} else  {
	$filename = sprintf( '%s/%s', $destination, basename( $icon ));
	$result = copy( $icon, $filename );
	if( $result ) {
		$cli->notice( sprintf( 'Icon file %s copied to %s', $icon, $filename ));
	} else {
		$cli->error( sprintf( 'Failed copying icon file %s', $icon ));
	}
}

$autorun = $ini->variable( $section, 'Autorun' );
$cli->notice( $autorun );
$tpl->setVariable( 'icon', basename( $icon ));
$page = $tpl->fetch( "design:" . $autorun );

$result = file_put_contents( sprintf( '%s/autorun.inf', $destination ), $page );
if( $result === false ) {
	$cli->error( sprintf( 'Failed writing autorun file', $icon ));
} else {
	$cli->notice( sprintf( 'Autorun file created (%d bytes)', $result ));
}

$str = exec( sprintf( 'du -h %s --max-depth=0', $destination ));
$str = preg_replace( '/\s.*/', '', $str );
$cli->output( 'Total file size: ' . $str );

/*  
$tarFile = $destination . '.tgz' ;
$tarDir = dirname( $tarFile );
$tarCommand = sprintf( "cd %s; tar -czf %s.tgz %s", $tarDir, $section, $section );
exec( $tarCommand );
$tarSize = filesize( $tarFile );
$cli->output( sprintf( 'Output tar/gzipped at %s (%.1fM)', $tarFile, $tarSize / 1024 / 1024 ));
*/

$zipFile = $destination . '.zip' ;
$zipDir = dirname( $zipFile );
if( file_exists( $zipFile )) {
	unlink( $zipFile );
}
$zipCommand = sprintf( "cd %s/%s; zip -r ../%s.zip *", $zipDir, $section, $section, $section, $section );
exec( $zipCommand );
$zipSize = filesize( $zipFile ) / 1024;
if( $zipSize < 1024  ) {
	$unit = 'K';
} else {
	$zipSize /= 1024;
	$unit = 'M';
}

$cli->output( sprintf( 'Output zipped at %s (%.1f%s)', $zipFile, $zipSize, $unit ));

$script->shutdown();

/**
 * Add node and recursively subnodes
 * updates global variables $nodeList and $nodeAliases
 *
 * @param class eZContentObjectTreeNode $node Node to be added
 * @param array $path Array of parent nodes from top node to parent of $node
 * @return void
 */

function addNode( $node, $path = array())
{
	global $cli, $nodeList, $nodeAliases;
	// $node is either an object or an ID
	$key = NODE_PREFIX . $node->NodeID;
	if( !array_key_exists( $key, $nodeList ) && count( $nodeList ) < MAX_NODES ) {
		$path[] = $node->NodeID;
		
		$nodeList[$key] = array( 'nodeID' => $node->MainNodeID, 'name' => $node->Name, 'path' => $path, 'children' => array());
		$nodeAliases[$node->MainNodeID] = $node->attribute( 'url_alias');
		$children = $node->children();
		foreach( $children as $child ) {
			$mainNodeID = $child->MainNodeID;
			$nodeList[$key]['children'][] = $mainNodeID;
			if( $child->NodeID != $mainNodeID ) {
				addNode( eZContentObjectTreeNode::fetch( $mainNodeID ), $path );
			} else {
				addNode( $child, $path );
			}
		}
	}
}

/**
 * Replace local link in page to link in exported site
 * used as callback function in preg_replace_callback
 * will copy files to exported site if necessary
 *
 * @param array $match Matches in regex pattern
 * @return string New link
 */

function replaceLink( $match )
{
/*
Sample $match:
    [0] => href="/design/standard/stylesheets/core.css"
    [1] => href
    [2] => /design/standard/stylesheets/core.css
*/
	global $nodeList, $nodeAliases, $siteURL, $destination;
	$ret = false;
	if( preg_match( '!/content/download/\d+/(\d+)/file/(.*)$!', $match[2], $submatch )) {
		// downloadable file?
		// sample: /redir/content/download/158403/562922/file/SourceBulletin62-2010.pdf
		// [0] => /content/download/158403/562922/file/SourceBulletin62-2010.pdf
		// [1] => 562922
		// [2] => SourceBulletin62-2010.pdf
		$attrID =& $submatch[1];
		$filename =& $submatch[2];
		$path = sprintf( '/file/%d/%s', $attrID, $filename );
		testdir( $destination . dirname( $path ));
		$binfile = eZBinaryFile::fetch( $submatch[1] );
		if( is_array( $binfile ) && is_object( $binfile[0] )) {
			copy( $binfile[0]->filePath(), $destination . $path );
		}
		$ret = sprintf( '%s="..%s"', $match[1], $path );
	} elseif( preg_match( '!/var/.*/storage/.*/(\d+)\-\d\-[a-z\-]+/([^/].*)$!i', $match[2], $submatch )) {;
		// Stored image?
		// sample: /var/irc/storage/images/media/images/ddm_photo/568225-1-eng-GB/ddm_photo_large.jpg
		// [0] => /var/irc/storage/images/media/images/ddm_photo/568225-1-eng-GB/ddm_photo_large.jpg
		// [1] => 568225
		// [2] => ddm_photo_large.jpg
		$attrID =& $submatch[1];
		$filename =& $submatch[2];
		$path = sprintf( '/image/%d/%s', $attrID, $filename );
		testdir( $destination . dirname( $path ));
		copy( substr( $match[2], 1), $destination . $path );
		$ret = sprintf( '%s="..%s"', $match[1], $path );
	} elseif( preg_match( '!/design/.*/([^/].*)$!', $match[2], $submatch )) {
		// Design element?
		// Sample: /extension/julian/design/ircstandard/stylesheets/all.css
		// [0] => /design/ircstandard/stylesheets/all.css
		// [1] => all.css
		$filename =& $submatch[1];
		$path = sprintf( '/design/%s', $filename );
		testdir( $destination . dirname( $path ));
		copy( substr( $match[2], 1 ), $destination . $path );
		$ret = sprintf( '%s="..%s"', $match[1], $path );
	} elseif( preg_match( '!^/(content/view/full)/(\d+)!', $match[2], $submatch )) {
		$key = NODE_PREFIX . $submatch[2];
		if( array_key_exists( $key, $nodeList )) {
			// Local copy exists
			$ret = sprintf( '%s="%d.html"', $match[1], $submatch[2] );
		}
	} elseif( preg_match( '!/url/(\d+)$!', $match[2], $submatch )) {
		$url = eZURL::fetch( $submatch[1] );
		$ret = sprintf( '%s="%s"', $match[1], $url->URL );
	} else {
		$nodeID = array_search( substr( $match[2], 1 ), $nodeAliases );
		if( $nodeID ) {
			$ret = sprintf( '%s="%d.html"', $match[1], $nodeID );
		}
	}
	if( $ret === false ) {
		// No local copy, add siteURL
		$ret = sprintf( '%s="http://%s%s"', $match[1], $siteURL, $match[2] );
	}
	return $ret;
}

/**
 * test if directory exists, if not create it
 *
 * @param string $dir Directory name
 * @return void
 */

function testdir( $dir )
{
	if( !is_dir( $dir )) {
		mkdir( $dir );
	}
}

/**
 * Create HTML content to redirect to another page
 *
 * @param string $url Page to redirect to
 * @return string HTML content
 */

function redirectPage( $url )
{
	return <<<EOD
<html>
<head>
<meta HTTP-EQUIV="REFRESH" content="0; url={$url}">
</head>
<body>Go to <a href="{$url}">{$url}</a></body>
</html>
EOD;
}
?>