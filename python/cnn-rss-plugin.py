
# CNN Live for XBMC - by Jim McGowen
# Gets news clips from various CNN RSS feeds and adds them to the queue which repeats all.
# Checks for new videos every minute and if any are found they are added to play next in the queue.

import xbmcplugin,xbmcgui,xbmcaddon,time,urllib2,calendar,xml.sax.saxutils,sys
from datetime import date, timedelta
from urllib2 import HTTPError
try:
    import json
except:
    import simplejson as json

# Constants
POLLING_INTERVAL_SECONDS = 300

# Settings
urls = []
__settings__ = xbmcaddon.Addon( id='plugin.video.cnn.live' )
if __settings__.getSetting( "us_news" ) == "true":
    urls += [ 'http://www.navixtreme.com/scrape/cnn/cat/by_section_us' ]
if __settings__.getSetting( "world_news" ) == "true":
    urls += [ 'http://www.navixtreme.com/scrape/cnn/cat/by_section_world' ]
if __settings__.getSetting( "entertainment_news" ) == "true":
    urls += [ 'http://www.navixtreme.com/scrape/cnn/cat/by_section_showbiz' ]
if __settings__.getSetting( "tech_news" ) == "true":
    urls += [ 'http://www.navixtreme.com/scrape/cnn/cat/by_section_tech' ]
if __settings__.getSetting( "health_news" ) == "true":
    urls += [ 'http://www.navixtreme.com/scrape/cnn/cat/by_section_health' ]
MAX_DAYS = int( __settings__.getSetting( "max_days" ) )

# Start the progress dialog
progress = xbmcgui.DialogProgress()
progressPercent = 0

# Global vars
playListNames = []
atEndOfPlaylist = False

class MyPlayer( xbmc.Player ):
    polling = True
    
    def __init__ ( self ):
        xbmc.Player.__init__( self )
        MyPlayer.polling = True
    
    # Manually stopped by user
    def onPlayBackStopped( self ):
        xbmc.log( "plugin.video.cnn.live: Playback stopped" )
        MyPlayer.polling = False
        # Show the queue
        xbmc.executebuiltin( 'XBMC.ActivateWindow(10028)' )
    
    # Last item in the queue has finished playing
    # Restart the queue from the first item
    def onPlayBackEnded( self ):
        global atEndOfPlaylist
        if atEndOfPlaylist == True:
            atEndOfPlaylist = False
            xbmc.log( "plugin.video.cnn.live: Restarting playlist" )
            xbmc.sleep( 1000 ) # Let things catch up.  Is there a better way to do this?
            play()

# Global player
player = MyPlayer()

def zuluToLocalDateTime( zdate, ztime ):
    zuluDateTime = time.strptime( zdate + ' ' + ztime, "%m/%d/%y %H:%M:%S" )
    zuluSec = calendar.timegm( zuluDateTime )
    localDateTime = time.localtime( zuluSec )
    ltime = time.strftime( "%a %m/%d %I:%M%p", localDateTime )
    
    return ltime
    
def getItems( url, filterDate = True ):
    items = []
    
    req = urllib2.Request( url )
    req.add_header( 'User-Agent', 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-GB; rv:1.8.1.14) Gecko/20080404 Firefox/2.0.0.14' )
    req.add_header( 'Referer', 'http://www.navixtreme.com' )
    try:
        response = urllib2.urlopen( req )
        content = response.read()
        response.close()
    except HTTPError, code:
        xbmc.log( "plugin.video.cnn.live: " + str( code ) + "  url=" + url )
    except:
        raise
    
    curIndex = 0
    while True:
        curIndex = content.find( 'name=', curIndex )
        if curIndex != -1:
            newLineIndex = content.find( "\n", curIndex )
            name = content[ curIndex + 5 : newLineIndex ]
            
            curIndex = content.find( 'thumb=', curIndex )
            newLineIndex = content.find( "\n", curIndex )
            thumbnail = content[ curIndex + 6 : newLineIndex ]
            #xbmc.log( thumbnail )
            
            curIndex = content.find( 'URL=', curIndex )
            newLineIndex = content.find( "\n", curIndex )
            url = content[ curIndex + 4 : newLineIndex ]
            
            # The date and time comes from the thumbnail url
            dateTimeIndex = thumbnail.find( 'assets/' )
            if dateTimeIndex != -1:
                dateTime = thumbnail[ dateTimeIndex + 7 : dateTimeIndex + 7 + 12 ]
                year = dateTime[ 0 : 2 ]
                month = dateTime[ 2 : 4 ]
                day = dateTime[ 4 : 6 ]
                hour = dateTime[ 6 : 8 ]
                minute = dateTime[ 8 : 10 ]
                second = dateTime[ 10 : 12 ]
                time = str( hour ) + ':' + str( minute ) + ':' + str( second )
                if filterDate:
                    oldestDate = date.today() - timedelta( MAX_DAYS )
                    videoDate = date( 2000 + int( year ), int( month ), int( day ) )
                    if videoDate > oldestDate:
                        items.append( (dateTime,name,thumbnail,url,year,month,day,time) )
                else:
                    items.append( (dateTime,name,thumbnail,url,year,month,day,time) )
                
                curIndex = newLineIndex + 1
            
        else:
            break
    
    return items

def doPolling():
    global playListNames
    global urls
    
    if( MyPlayer.polling == False ):
        return
    
    xbmc.log( "plugin.video.cnn.live: doPolling" )
    
    newCount = 0
    for url in urls:
        match = getItems( url )
        if len( match ) == 0:
            return
        
        newCount += addItems( match, True )
            
    xbmc.log( "plugin.video.cnn.live: Inserted " + str( newCount ) + " new items to the playlist" )
    
    if newCount > 0:
        xbmc.executebuiltin( 'Notification(CNN Live, Found ' + str( newCount ) + ' new videos.,10000)' )
    
def play():
    global player
    
    playlist = xbmc.PlayList( xbmc.PLAYLIST_VIDEO )
    if playlist.size() > 0:
        xbmc.log( "plugin.video.cnn.live: Playing playlist (queue)" )
        player.play( playlist )

def addItems( items, insert = False ):
    global progress, progressPercent
    global playListNames
    
    itemCount = len( items )
    xbmc.log( "itemCount = " + str( itemCount ) )
    addCount = 0
    playlist = xbmc.PlayList( xbmc.PLAYLIST_VIDEO )
    curIndex = playlist.getposition()
    for dateTime,name,thumbnail,url,Year,Month,Day,Time in items:
        name = xml.sax.saxutils.unescape( name )
        localTime = zuluToLocalDateTime( Month + '/' + Day + '/' + Year, Time )
        label = localTime + ' - ' + name
        if label not in playListNames:
            name = xml.sax.saxutils.unescape( name )
            localTime = zuluToLocalDateTime( Month + '/' + Day + '/' + Year, Time )
            label = localTime + ' - ' + name
            li = xbmcgui.ListItem( label, iconImage="DefaultVideo.png" )
            li.setInfo('video', {'Title': label, 'Description':label} )
            li.setThumbnailImage( thumbnail )
            playListNames.append( label )
            if insert == True:
                playlist.add( url, li, curIndex + addCount + 1 )
            else:
                playlist.add( url, li )
                if progress.iscanceled():
                    sys.exit( 0 )
                percent = 100 / itemCount
                if percent <= 0:
                    progressPercent += 1
                else:
                    progressPercent += percent
                #xbmc.log( "progressPercent = " + str( progressPercent ) )
                progress.update( progressPercent )
            
            addCount += 1
        
    return addCount

def startQueue():
    global progress, progressPercent
    global playListNames
    global urls
    
    xbmc.log( "plugin.video.cnn.live: Building playlist (queue)" )
    progress.create( "CNN Live", "Getting videos from CNN..." )
    progress.update( 0 )
    playlist = xbmc.PlayList( xbmc.PLAYLIST_VIDEO )
    playlist.clear()
    
    addCount = 0
    match = []
    urlCount = len( urls )
    for url in urls:
        xbmc.log( 'Getting news from ' + url )
        match += getItems( url )
        if progress.iscanceled():
            sys.exit( 0 )
        percent = 100 / urlCount
        if percent <= 0:
            progressPercent += 1
        else:
            progressPercent += percent
        progress.update( progressPercent )
    
    progress.close()
    progress.create( "CNN Live", "Adding videos to playlist..." )
    progressPercent = 0
    progress.update( progressPercent )
    
    #random.shuffle( match )
    # Sort items by date and time descending
    match.sort( None, None, True )
    addCount += addItems( match, False )
    
    progress.close()
    
    if addCount == 0:
        dialog = xbmcgui.Dialog()
        dialog.ok( 'CNN News', 'Sorry, no videos were found. The feed might be down. Please try again.' )
        MyPlayer.polling = False
        sys.exit( 0 )
    
    xbmc.log( "plugin.video.cnn.live: Added " + str( addCount ) + " items to the playlist" )

# Initialize the queue and start playing
try:
    startQueue()
    if MyPlayer.polling == True:
        play()
# sys.exit( 0 ) produces a SystemExit exception. This except is here to ignore it.
except SystemExit:
    progress.close()
    pass
# For all other exceptions
except:
    progress.close()
    raise

# Start the polling loop
# When onPlayBackStopped is detected the loop breaks and the script ends.
lastTime = time.time()
while MyPlayer.polling == True:
    xbmc.sleep(10)
    
    playlist = xbmc.PlayList(xbmc.PLAYLIST_VIDEO)
    
    # Check to see if we are at the end of the playlist
    # This will trigger onPlayBackEnded to restart the queue
    curPos = playlist.getposition()
    if curPos == playlist.size() - 1:
        atEndOfPlaylist = True
        
    # Check the time, if it's been POLLING_INTERVAL_SECONDS since the last polling then doPolling
    thisTime = time.time()
    if thisTime - lastTime >= POLLING_INTERVAL_SECONDS:
        doPolling()
        lastTime = thisTime
        xbmc.log( "plugin.video.cnn.live: " + str( len( playListNames ) ) + ":" + str( playlist.size() ) + " items in the playlist" )

xbmc.log( "plugin.video.cnn.live: Done" )