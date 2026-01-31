"""
EmberCortex RAG Server
FastAPI service for RAG queries with ChromaDB + LlamaIndex
"""
import chromadb
from fastapi import FastAPI, HTTPException, UploadFile, File, Form
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
from typing import Optional, List
import os
import tempfile
import shutil
import hashlib

from llama_index.core import (
    VectorStoreIndex,
    SimpleDirectoryReader,
    StorageContext,
    Settings,
)
from llama_index.core.node_parser import SentenceSplitter
from llama_index.vector_stores.chroma import ChromaVectorStore
from llama_index.embeddings.huggingface import HuggingFaceEmbedding
from llama_index.llms.openai_like import OpenAILike

import config

# Initialize LlamaIndex settings
Settings.llm = OpenAILike(
    model=config.LLM_MODEL,
    api_base=config.LLM_API_BASE,
    api_key="not-needed",
    is_chat_model=True,
    temperature=0.1,
    max_tokens=4096,
    context_window=16384,  # Must match llama.cpp context length
)

# Use llama.cpp embedding server (GPU-accelerated)
from llama_index.embeddings.openai import OpenAIEmbedding

Settings.embed_model = OpenAIEmbedding(
    api_base="http://127.0.0.1:8081/v1",
    api_key="not-needed",
    model_name="nomic-embed-text-v1.5",
    embed_batch_size=8,  # Match parallel slots in embed-server
)

Settings.chunk_size = config.CHUNK_SIZE
Settings.chunk_overlap = config.CHUNK_OVERLAP

# Initialize FastAPI
app = FastAPI(
    title="EmberCortex RAG Server",
    description="RAG service for local LLM with ChromaDB",
    version="0.1.0",
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Initialize ChromaDB
chroma_client = chromadb.PersistentClient(path=str(config.CHROMA_DIR))

# Index cache
index_cache = {}


class QueryRequest(BaseModel):
    query: str
    collection: str = "default"
    top_k: int = 5
    include_sources: bool = True


class QueryResponse(BaseModel):
    answer: str
    sources: Optional[List[dict]] = None


class IngestRequest(BaseModel):
    collection: str
    directory: str


class CollectionInfo(BaseModel):
    name: str
    description: Optional[str] = None
    count: int = 0


def get_index(collection_name: str) -> VectorStoreIndex:
    """Get or create an index for a collection."""
    if collection_name in index_cache:
        return index_cache[collection_name]
    
    try:
        chroma_collection = chroma_client.get_collection(collection_name)
    except Exception:
        raise HTTPException(
            status_code=404,
            detail=f"Collection '{collection_name}' not found. Create it first by ingesting documents."
        )
    
    vector_store = ChromaVectorStore(chroma_collection=chroma_collection)
    storage_context = StorageContext.from_defaults(vector_store=vector_store)
    index = VectorStoreIndex.from_vector_store(
        vector_store,
        storage_context=storage_context,
    )
    index_cache[collection_name] = index
    return index


@app.get("/")
async def root():
    return {"service": "EmberCortex RAG Server", "status": "running"}


@app.get("/health")
async def health():
    return {"status": "ok"}


@app.get("/collections")
async def list_collections():
    """List all available collections."""
    collections = chroma_client.list_collections()
    return {
        "collections": [
            {
                "name": c.name,
                "metadata": c.metadata,
                "count": c.count(),
            }
            for c in collections
        ]
    }


@app.post("/collections/{name}")
async def create_collection(name: str, description: str = ""):
    """Create a new empty collection."""
    try:
        chroma_client.get_or_create_collection(
            name=name,
            metadata={"description": description}
        )
        return {"status": "created", "name": name}
    except Exception as e:
        raise HTTPException(status_code=400, detail=str(e))


@app.delete("/collections/{name}")
async def delete_collection(name: str):
    """Delete a collection."""
    try:
        chroma_client.delete_collection(name)
        if name in index_cache:
            del index_cache[name]
        return {"status": "deleted", "name": name}
    except Exception as e:
        raise HTTPException(status_code=400, detail=str(e))


class CollectionUpdate(BaseModel):
    description: Optional[str] = None
    source: Optional[str] = None


@app.patch("/collections/{name}")
async def update_collection(name: str, update: CollectionUpdate):
    """Update collection metadata (description, source)."""
    try:
        collection = chroma_client.get_collection(name)
        metadata = collection.metadata or {}
        
        if update.description is not None:
            metadata["description"] = update.description
        if update.source is not None:
            metadata["source"] = update.source
        
        collection.modify(metadata=metadata)
        return {"status": "updated", "name": name, "metadata": metadata}
    except Exception as e:
        raise HTTPException(status_code=400, detail=str(e))


@app.get("/collections/{name}/chunks")
async def get_collection_chunks(name: str, limit: int = 50, offset: int = 0):
    """Get chunks/documents in a collection."""
    try:
        collection = chroma_client.get_collection(name)
    except Exception:
        raise HTTPException(status_code=404, detail=f"Collection '{name}' not found")
    
    total = collection.count()
    
    # Get chunks with metadata
    results = collection.get(
        limit=limit,
        offset=offset,
        include=["documents", "metadatas"]
    )
    
    chunks = []
    for i, doc_id in enumerate(results['ids']):
        chunks.append({
            "id": doc_id,
            "text": results['documents'][i][:300] + "..." if len(results['documents'][i]) > 300 else results['documents'][i],
            "metadata": results['metadatas'][i] if results['metadatas'] else {},
        })
    
    return {
        "collection": name,
        "total": total,
        "offset": offset,
        "limit": limit,
        "chunks": chunks,
    }


@app.delete("/collections/{name}/clear")
async def clear_collection(name: str):
    """Clear all documents from a collection but keep the collection."""
    try:
        collection = chroma_client.get_collection(name)
        metadata = collection.metadata
        
        # Delete and recreate to clear
        chroma_client.delete_collection(name)
        chroma_client.create_collection(name=name, metadata=metadata)
        
        if name in index_cache:
            del index_cache[name]
        
        return {"status": "cleared", "name": name}
    except Exception as e:
        raise HTTPException(status_code=400, detail=str(e))


@app.post("/query", response_model=QueryResponse)
async def query_rag(request: QueryRequest):
    """Query the RAG system."""
    index = get_index(request.collection)
    
    query_engine = index.as_query_engine(
        similarity_top_k=request.top_k,
        response_mode="compact",
    )
    
    response = query_engine.query(request.query)
    
    sources = None
    if request.include_sources and response.source_nodes:
        sources = [
            {
                "text": node.text[:500] + "..." if len(node.text) > 500 else node.text,
                "score": node.score,
                "metadata": node.metadata,
            }
            for node in response.source_nodes
        ]
    
    return QueryResponse(
        answer=str(response),
        sources=sources,
    )


def compute_doc_hash(content: str, file_path: str) -> str:
    """Compute a hash for document deduplication."""
    return hashlib.sha256(f"{file_path}:{content}".encode()).hexdigest()[:16]


@app.post("/ingest/directory")
async def ingest_directory(request: IngestRequest):
    """Ingest documents from a directory into a collection. Skips duplicates based on content hash."""
    if not os.path.isdir(request.directory):
        raise HTTPException(status_code=400, detail=f"Directory not found: {request.directory}")
    
    # Get or create collection and update source metadata (preserve existing metadata)
    chroma_collection = chroma_client.get_or_create_collection(
        name=request.collection,
    )
    # Merge source into existing metadata (preserve description, etc.)
    existing_metadata = chroma_collection.metadata or {}
    existing_metadata["source"] = request.directory
    chroma_collection.modify(metadata=existing_metadata)
    
    # Get existing document hashes from collection
    existing_hashes = set()
    if chroma_collection.count() > 0:
        existing = chroma_collection.get(include=["metadatas"])
        for meta in existing['metadatas']:
            if meta and 'doc_hash' in meta:
                existing_hashes.add(meta['doc_hash'])
    
    # Load documents
    documents = SimpleDirectoryReader(
        input_dir=request.directory,
        recursive=True,
        required_exts=[".py", ".md", ".txt", ".rst", ".json", ".yaml", ".yml", ".js", ".ts", ".jsx", ".tsx", ".html", ".css", ".sh", ".bash", ".php", ".c", ".h", ".cpp", ".hpp", ".go", ".rs", ".java", ".rb", ".lua"],
    ).load_data()
    
    if not documents:
        raise HTTPException(status_code=400, detail="No documents found in directory")
    
    # Filter out duplicates based on content hash
    new_documents = []
    skipped = 0
    for doc in documents:
        file_path = doc.metadata.get('file_path', '')
        doc_hash = compute_doc_hash(doc.text, file_path)
        
        if doc_hash in existing_hashes:
            skipped += 1
            continue
        
        # Add hash to metadata for future dedup
        doc.metadata['doc_hash'] = doc_hash
        new_documents.append(doc)
        existing_hashes.add(doc_hash)
    
    if not new_documents:
        return {
            "status": "success",
            "collection": request.collection,
            "documents_ingested": 0,
            "documents_skipped": skipped,
            "total_chunks": chroma_collection.count(),
            "message": "All documents already exist in collection"
        }
    
    # Create vector store and index only new documents
    vector_store = ChromaVectorStore(chroma_collection=chroma_collection)
    storage_context = StorageContext.from_defaults(vector_store=vector_store)
    
    index = VectorStoreIndex.from_documents(
        new_documents,
        storage_context=storage_context,
        transformations=[SentenceSplitter(chunk_size=config.CHUNK_SIZE, chunk_overlap=config.CHUNK_OVERLAP)],
        show_progress=True,
    )
    
    # Update cache
    index_cache[request.collection] = index
    
    return {
        "status": "success",
        "collection": request.collection,
        "documents_ingested": len(new_documents),
        "documents_skipped": skipped,
        "total_chunks": chroma_collection.count(),
    }


@app.post("/ingest/files/{collection}")
async def ingest_files(
    collection: str,
    files: List[UploadFile] = File(...),
):
    """Upload and ingest files into a collection."""
    # Create temp directory for uploads
    with tempfile.TemporaryDirectory() as temp_dir:
        # Save uploaded files
        for file in files:
            file_path = os.path.join(temp_dir, file.filename)
            with open(file_path, "wb") as f:
                shutil.copyfileobj(file.file, f)
        
        # Ingest from temp directory
        request = IngestRequest(collection=collection, directory=temp_dir)
        return await ingest_directory(request)


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host=config.RAG_HOST, port=config.RAG_PORT)
