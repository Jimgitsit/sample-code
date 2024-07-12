import requests
import csv
from progressBar import printProgressBar


inputFile = "reviews_2018-10-30.csv"
outputFile = "reviews_with_sku_2018-10-30.csv"
m2Host = "https://www.kurufootwear.com"
m2ApiUrl = m2Host + "/index.php/rest/V1"
m2ApiToken = "nycdumxygb9hoyfiq841eb7qo32pgl47"
m2UrlProductBySku = m2ApiUrl + "/products?searchCriteria[filterGroups][0][filters][0][field]=sku&searchCriteria[filterGroups][0][filters][0][condition_type]=eq&searchCriteria[filterGroups][0][filters][0][value]="
m2UrlProductById = m2ApiUrl + "/products?searchCriteria[filterGroups][0][filters][0][field]=entity_id&searchCriteria[filterGroups][0][filters][0][condition_type]=eq&searchCriteria[filterGroups][0][filters][0][value]="
skuCache = {}


def get_product_by_sku(productSku):
    url = m2UrlProductBySku + productSku
    data = []
    headers = {
        "Content-Type": "application/json",
        "Authorization": "Bearer " + m2ApiToken
    }
    response = requests.get(url, data=data, headers=headers)
    if (response.status_code != 200):
        print("Got bad response from M2 API.")
        print(url)
        print(response)
        exit()

    json = response.json()
    items = json.get("items")
    if len(items) == 0:
        return None

    # We want the grandparent product
    parentSku = ''
    attributes = items[0].get("custom_attributes")
    for attribute in attributes:
        if attribute.get("attribute_code") == "parent_sku":
            parentSku = attribute.get("value")

    if parentSku:
        return get_product_by_sku(parentSku)

    return json


def get_product_by_id(productId):
    url = m2UrlProductById + productId
    data = []
    headers = {
        "Content-Type": "application/json",
        "Authorization": "Bearer " + m2ApiToken
    }
    response = requests.get(url, data=data, headers=headers)
    if (response.status_code != 200):
        print("Got bad response from M2 API.")
        print(url)
        print(response)
        exit()

    json = response.json()
    items = json.get("items")
    if len(items) == 0:
        return None

    # Check for a parent SKU and if there is one get that product
    parentSku = ''
    attributes = items[0].get("custom_attributes")
    for attribute in attributes:
        if attribute.get("attribute_code") == "parent_sku":
            parentSku = attribute.get("value")

    if parentSku:
        return get_product_by_sku(parentSku)

    return json


def get_product_sku(productId):
    if productId in skuCache:
        return skuCache[productId]

    product = get_product_by_id(productId)
    if product is None:
        print("Got unknown product ID: %s" % productId)
        exit()

    items = product.get("items")
    sku = items[0].get("sku")
    skuCache[productId] = sku
    return sku


def load_data(fileName):
    data = []
    with open(fileName, 'r', newline='') as csvFile:
        reader = csv.reader(csvFile, delimiter=",", quotechar='"')
        for row in reader:
            data.append(row)

    return data


def save_data(newfileName, newData):
    with open(newfileName, 'w+', newline='') as csvfile:
        writer = csv.writer(csvfile, delimiter=',', quotechar='"', quoting=csv.QUOTE_MINIMAL)
        for row in newData:
            writer.writerow(row)


def add_skus(orginalData):
    size = len(orginalData)
    newData = []
    rowNum = 0
    for row in orginalData:
        rowNum += 1
        if rowNum == 1:
            # Header row
            newRow = row
            newRow.append("sku")
            newData.append(newRow)
            continue

        productId = row[8]
        if productId == 'yotpo_site_reviews':
            sku = ''
        else:
            sku = get_product_sku(productId)

        newRow = row
        newRow.append(sku)
        newData.append(newRow)
        printProgressBar(rowNum, size, prefix='Progress:', suffix='Complete', length=50)

    return newData


print("Reading data from " + inputFile + "...")
originalData = load_data(inputFile)
print("Adding SKUs...")
printProgressBar(0, len(originalData), prefix='Progress:', suffix='Complete', length=50)
newData = add_skus(originalData)
print("Saving new data...")
save_data(outputFile, newData)
print("Done.")
