from get_trivai_database import get_trivai_database

# Get the database
db = get_trivai_database()

# Find all duplicates in the questions collection
dups = db['questions'].aggregate([
    { '$match': {
        '$or': [
            {'flagged': { '$eq': False }},
            {'flagged': { '$exists': False }}
        ],
        'flagged_reason': { '$ne': 'duplicate' }
    }},
    { '$group': {
        '_id': { 'question': '$question' },
        'uniqueIds': { '$addToSet': '$_id' },
        'count': { '$sum': 1 }
    }},
    { '$match': {
        'count': { '$gt': 1 }
    }}
])

dupQuestions = list(dups)

print('Found ' + str(len(dupQuestions)) + ' duplicates')

ids = []

flaggedCount = 0

# Print the duplicates
for dup in dupQuestions:
    print(str(dup['count']) + ": " + dup['_id']['question'])
    ids = ids + dup['uniqueIds']
    for idx, id in enumerate(dup['uniqueIds']):
        if (idx == 0):
            print('  Keeping ' + str(id))
        else:
            # Mark the question as a duplicate
            question = db['questions'].find_one({'_id': id})
            question['flagged'] = True
            question['flagged_reason'] = 'duplicate'
            db['questions'].replace_one({'_id': id}, question)
            flaggedCount = flaggedCount + 1
            print('  Marked ' + str(id) + ' as duplicate')

print('Flagged ' + str(flaggedCount) + ' questions as duplicates')
print('Finished.')
