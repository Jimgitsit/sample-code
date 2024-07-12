#!/usr/local/bin/python

import time
import sys
from seleniumwire import webdriver
from selenium.webdriver.chrome.options import Options
from datetime import datetime

MAX_RESPONSE_TIME = 1000

iterations = sys.argv[1]
delaySeconds = sys.argv[2]
url2 = sys.argv[3]
head = sys.argv[4]
webscale = sys.argv[5]

options = Options()
#options.binary_location = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
if head != 'show':
    options.add_argument('--headless')

startDt = datetime.now()

print("Requesting " + url2 + " " + iterations + " times with a " + delaySeconds + " second delay, new session for each request...")

respTimes = []
ttfbTimes = []
cacheHits = []
failCount = 0
m2CacheHitCount = 0
wsCacheHitCount = 0

# Chrome init
driver = webdriver.Chrome(chrome_options=options)
driver.set_window_size(1920, 1080)  # Desktop
driver.set_page_load_timeout(30)
driver.implicitly_wait(30)

for i in range(0, int(iterations)):
    # Chrome init
    #driver = webdriver.Chrome(chrome_options=options)
    #driver.set_window_size(1920, 1080)  # Desktop
    #driver.set_page_load_timeout(30)
    #driver.implicitly_wait(30)

    # webscale header
    # useragent = 'kurufootwear'
    if webscale == 'true':
        # driver.add_cookie({"name": "kuru-testing", "value": "*"})
        driver.header_overrides = {
            # 'User-Agent': useragent,
            'Webscale-Debug': '*',
            # 'Cookie': 'kuru-testing=*'
        }
    
    # Make the request
    try:
        driver.get(url2)
    except:
        print("Request: " + str(i + 1) + " Failed.")
        failCount += 1
        continue

    # Check response
    response = driver.requests[0].response
    if response is None:
        print("Request: " + str(i + 1) + " No response.")
        failCount += 1
        continue

    if response.status_code != 200:
        print("Request: " + str(i + 1) + " Response Code: " + str(response.status_code))
        failCount += 1
        continue

    # Get request and response headers
    # if driver.requests[0].headers['user-agent'] != 'kurufootwear':
    #    useragent = '(other)'
    wsDebugCache = ''
    wsDebugControl = ''
    xmagentoCache = response.headers['X-Magento-Cache-Debug']
    if webscale == 'true':
        wsDebugCache = response.headers['webscale-debug-cache']
        wsDebugControl = response.headers['webscale-debug-webcontrol']

    navigationStartTime = driver.execute_script("return window.performance.timing.navigationStart")
    requestStartTime = driver.execute_script("return window.performance.timing.requestStart")
    responseStartTime = driver.execute_script("return window.performance.timing.responseStart")
    domLoadingTime = driver.execute_script("return window.performance.timing.domLoading")
    domCompleteTime = driver.execute_script("return window.performance.timing.domComplete")
    loadEndTime = driver.execute_script("return window.performance.timing.loadEventEnd")
    
    #ttfb = float(responseStartTime - navigationStartTime) / 1000
    ttfb = float(responseStartTime - requestStartTime) / 1000
    frontendPerformance = float(loadEndTime - responseStartTime) / 1000
    domComplete = float(domCompleteTime - domLoadingTime) / 1000
    #fullyLoaded = float(loadEndTime - navigationStartTime) / 1000
    fullyLoaded = float(domCompleteTime - requestStartTime) / 1000
    #fullyLoaded = float(loadEndTime - requestStartTime) / 1000

    respTimes.append(fullyLoaded)
    ttfbTimes.append(ttfb)

    wsDebugCacheHit = ''
    if webscale == 'true':
        if 'Hit' in wsDebugCache:
            wsDebugCacheHit = 'HIT'
            xmagentoCache = 'N/A'
            wsCacheHitCount += 1
            cacheHits.append(1)
        else:
            wsDebugCacheHit = 'MISS'
            if xmagentoCache == 'HIT':
                cacheHits.append(1)
                m2CacheHitCount += 1
            else:
                cacheHits.append(0)
    else:
        wsDebugCacheHit = 'N/A'
        if xmagentoCache == 'HIT':
            cacheHits.append(1)
            m2CacheHitCount += 1
        else:
            cacheHits.append(0)

    #print("Request: " + str(i + 1) + ", Page Load: " + str(round(fullyLoaded, 2)) + ", TTFB: " + str(round(backendPerformance, 2)) + ", FE: " + str(round(frontendPerformance, 2)))
    #print("domComplete : " + str(domCompleteTime) + " requestStart: " + str(requestStartTime))
    print("Request: " + (str(i + 1) + ':').ljust(5) + "TTFB: " + (f'{ttfb:.2f}' + ',').ljust(7) + "Dom Complete: " + (f'{domComplete:.2f}' + ',').ljust(7) + " Load Time: " + (f'{fullyLoaded:.2f}' + ',').ljust(7) + " WS/ADC Cache: " + (wsDebugCacheHit + ',').ljust(5) + " Magento Cache: " + xmagentoCache)
    #print("  webscale-debug-cache: " + wsDebugCacheHit + ", webscale-debug-webcontrol: " + wsDebugControl + ", X-Magento-Cache-Debug: " + xmagentoCache)
    #print("  WS/ADC Cache: " + wsDebugCacheHit + ", Magento Cache: " + xmagentoCache)

    # if diff > MAX_RESPONSE_TIME:
        # Write the network log to the log file for slow responses
        # f = open("slow-response-log.txt", "a")
        # f.write(proxy.har)
        # f.close()
    
    # if fullyLoaded > MAX_RESPONSE_TIME:
    #     print("Halting for response time of " + fullyLoaded)
    #     break
    
    if i < int(iterations):
        time.sleep(int(delaySeconds))

try:
    driver.close()
    driver.quit()
except:
    print("Exception closing and quiting webdriver.")
    #continue

endDt = datetime.now()

print("\nSummary")
print('URL: ' + url2)

if webscale == 'true':
    print('Cache: WS/ADC')
else:
    print('Cache: Magento')

print('Test start: ' + startDt.strftime("%d/%m/%Y %H:%M:%S"))
print('Test end: ' + endDt.strftime("%d/%m/%Y %H:%M:%S"))

successfulIterations = int(iterations) - failCount
print("Total requests: " + str(successfulIterations))

hitPercent = 0

if webscale == 'true':
    print("WS/ADC Cache Hits: " + str(wsCacheHitCount))
    hitPercent = round((wsCacheHitCount / successfulIterations) * 100, 2)
else:
    print("Magento Cache Hits: " + str(m2CacheHitCount))
    hitPercent = round((m2CacheHitCount / successfulIterations) * 100, 2)
#print("WS Cache Hits: " + str(wsCacheHitCount))


print("Hit %: " + str(hitPercent))

# Remove outliers
# outliersCount = 0
# respTimesOutliers = []
# for i, v in enumerate(respTimes):
#     if respTimes[int(i)] > MAX_RESPONSE_TIME:
#         respTimesOutliers.append(int(i))
#         outliersCount += 1

# Remove cache misses
# cacheMisses = []
# for i, v in enumerate(cacheHits):
#     if cacheHits[int(i)] == 0:
#         cacheMisses.append(int(i))

#totalMisses = len(cacheMisses)
#totalHits = len(cacheHits) - totalMisses

# respTimesHitsOnly = []
# for i, v in enumerate(respTimes):
#     if i not in cacheMisses:
#         respTimesHitsOnly.append(respTimes[int(i)])
#
# ttfbTimesHitsOnly = []
# for i, v in enumerate(ttfbTimes):
#     if i not in cacheMisses:
#         ttfbTimesHitsOnly.append(ttfbTimes[int(i)])
     
# Calc averages
avgRespTime = sum(respTimes) / len(respTimes)
#avgRespTimeNoOutliers = (sum(respTimes) - sum(respTimesOutliers)) / len(respTimes)
# avgRespTimeHitsOnly = -1
# if len(respTimesHitsOnly) > 0:
#     avgRespTimeHitsOnly = sum(respTimesHitsOnly) / len(respTimesHitsOnly)
avgTtfbTime = sum(ttfbTimes) / len(ttfbTimes)
# avgTtfbTimeHitsOnly = -1
# if len(ttfbTimesHitsOnly) > 0:
#     avgTtfbTimeHitsOnly = sum(ttfbTimesHitsOnly) / len(ttfbTimesHitsOnly)

# Show results
# print("Outliers removed: " + str(outliers) + " (" + str(MAX_RESPONSE_TIME) + " seconds max)")
print("Avg load time: " + str(round(avgRespTime, 2)))
print("Max load time: " + str(round(max(respTimes), 2)))
print("Avg TTFB     : " + str(round(avgTtfbTime, 2)))
print("Max TTFB     : " + str(round(max(ttfbTimes), 2)))
# print("Avg load time HITs only: " + str(round(avgRespTimeHitsOnly, 2)))
# print("Avg TTFB      HITs only: " + str(round(avgTtfbTimeHitsOnly, 2)))
# print("Avg load time without outliers (" + str(len(respTimes) - len(respTimesOutliers)) + "/" + str(iterations) + "): " + str(round(avgRespTimeNoOutliers, 2)))
print("\n")
